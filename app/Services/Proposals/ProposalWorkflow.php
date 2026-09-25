<?php

namespace App\Services\Proposals;

use App\Enums\PaymentStatus;
use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Enums\VoteValue;
use App\Models\OrderProposal;
use App\Models\Payment;
use App\Models\Pickup;
use App\Models\ProposalItem;
use App\Models\ProposalVote;
use App\Models\Round;
use App\Models\User;
use App\Services\Money\OrderCalculator;
use App\Services\Rounds\ConsensusChecker;
use App\Services\Rounds\ProposalConsensus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * State changes of an order proposal and the rules that guard them:
 * publishing, withdrawing, voting and choosing the final order.
 */
class ProposalWorkflow
{
    public const MIN_REASON_LENGTH = 3;

    public function __construct(
        private ConsensusChecker $consensus,
        private OrderCalculator $calculator,
    ) {}

    public function publish(OrderProposal $proposal, User $by): void
    {
        $round = $proposal->round;

        $this->ensure($proposal->isDraft(), 'Nur Entwürfe können zur Abstimmung freigegeben werden.');
        $this->ensure($this->isVotingPhase($round), 'Vorschläge können nur in der Verhandlungs- oder Bestätigungsphase freigegeben werden.');
        $this->ensure($proposal->items()->exists(), 'Der Vorschlag hat noch keine Positionen.');
        $this->ensure(
            $this->consensus->evaluate($proposal)->excludedStakeholderIds === [],
            'Der Vorschlag enthält ausgeschlossene Teilnehmer — bitte eine neue Version erstellen.',
        );

        $proposal->update([
            'status' => ProposalStatus::Published,
            'published_at' => now(),
        ]);

        $round->logActivity('proposal_published', ['proposal_id' => $proposal->id, 'title' => $proposal->title]);
    }

    public function withdraw(OrderProposal $proposal, User $by, ?string $reason = null): void
    {
        $this->ensure(
            in_array($proposal->status, [ProposalStatus::Draft, ProposalStatus::Published], true),
            'Dieser Vorschlag kann nicht mehr zurückgezogen werden.',
        );

        $proposal->update(['status' => ProposalStatus::Withdrawn]);

        $proposal->round->logActivity('proposal_withdrawn', [
            'proposal_id' => $proposal->id,
            'title' => $proposal->title,
            'reason' => $reason,
        ]);
    }

    public function delete(OrderProposal $proposal): void
    {
        $this->ensure($proposal->isDraft(), 'Nur Entwürfe können gelöscht werden.');

        $proposal->delete();
    }

    /**
     * A thumbs down needs a reason, so the lead knows what to change.
     */
    public function vote(ProposalItem $item, User $voter, VoteValue $value, ?string $reason = null): ProposalVote
    {
        $proposal = $item->proposal;
        $round = $proposal->round;
        $participant = $round->participantFor($voter);
        $reason = filled($reason) ? trim($reason) : null;

        $this->ensure($proposal->isPublished(), 'Abstimmen ist nur bei freigegebenen Vorschlägen möglich.');
        $this->ensure($this->isVotingPhase($round), 'In dieser Phase wird nicht mehr abgestimmt.');
        $this->ensure($participant !== null, 'Nur Teilnehmer der Runde können abstimmen.');
        $this->ensure(! $participant->removed, 'Du wurdest aus dieser Runde ausgeschlossen.');
        $this->ensure(
            $value === VoteValue::Up || mb_strlen((string) $reason) >= self::MIN_REASON_LENGTH,
            'Bitte begründe dein Daumen runter.',
        );

        return ProposalVote::updateOrCreate(
            ['proposal_item_id' => $item->id, 'user_id' => $voter->id],
            ['value' => $value, 'reason' => $value === VoteValue::Down ? $reason : null],
        );
    }

    /**
     * Only a unanimously approved proposal can become the final order.
     * Choosing it creates the payment and pickup list.
     */
    public function choose(OrderProposal $proposal, User $by): void
    {
        $round = $proposal->round;

        $this->ensure($round->phase === RoundPhase::Finalizing, 'Die finale Bestellung wird in der Bestätigungsphase gewählt.');
        $this->ensure($proposal->isPublished(), 'Nur freigegebene Vorschläge können gewählt werden.');

        $consensus = $this->consensus->evaluate($proposal);
        $this->ensure($consensus->isUnanimous(), $this->explainMissingConsensus($consensus));

        DB::transaction(function () use ($proposal, $round): void {
            $round->proposals()
                ->whereKeyNot($proposal->id)
                ->where('status', ProposalStatus::Chosen->value)
                ->update(['status' => ProposalStatus::Published->value]);

            $proposal->update(['status' => ProposalStatus::Chosen]);
            $round->update(['chosen_proposal_id' => $proposal->id]);

            $this->syncPaymentsAndPickups($proposal->fresh(['items.allocations', 'round.participants']));

            $round->logActivity('proposal_chosen', ['proposal_id' => $proposal->id, 'title' => $proposal->title]);
        });
    }

    /**
     * Undoes the choice of the final order while the round is still being
     * confirmed, e.g. after an exclusion or when going back to negotiating.
     */
    public function unchoose(Round $round): void
    {
        if ($round->newQuery()->whereKey($round->id)->value('chosen_proposal_id') === null) {
            return;
        }

        DB::transaction(function () use ($round): void {
            $round->proposals()
                ->where('status', ProposalStatus::Chosen->value)
                ->update(['status' => ProposalStatus::Published->value]);

            // Query update: the given model may be stale and already hold null.
            $round->newQuery()->whereKey($round->id)->update(['chosen_proposal_id' => null]);
            $round->setAttribute('chosen_proposal_id', null)->syncOriginalAttribute('chosen_proposal_id');

            $round->payments()->where('status', PaymentStatus::Pending->value)->delete();
            $round->pickups()->whereNull('picked_up_at')->delete();
        });
    }

    public function explainMissingConsensus(ProposalConsensus $consensus): string
    {
        if ($consensus->items === []) {
            return 'Der Vorschlag hat keine Positionen.';
        }

        if ($consensus->excludedStakeholderIds !== []) {
            return 'Der Vorschlag enthält ausgeschlossene Teilnehmer — bitte eine neue Version erstellen.';
        }

        $names = fn (array $ids): string => User::query()->whereKey($ids)->get()
            ->map(fn (User $user): string => $user->fullName())
            ->sort()
            ->implode(', ');

        $parts = [];

        if ($rejections = $consensus->rejections()) {
            $parts[] = 'Daumen runter von '.$names(array_keys($rejections));
        }

        if ($pending = $consensus->pendingUserIds()) {
            $parts[] = 'noch keine Stimme von '.$names($pending);
        }

        if ($parts === []) {
            return 'Mindestens eine Position hat niemanden, der sie bekommt — bitte den Vorschlag anpassen.';
        }

        return 'Noch nicht einstimmig: '.implode('; ', $parts).'.';
    }

    private function syncPaymentsAndPickups(OrderProposal $proposal): void
    {
        $round = $proposal->round;
        $totals = $this->calculator->calculate($proposal);
        $includedUserIds = collect($totals->perParticipant)->pluck('userId')->all();

        $round->payments()->whereNotIn('user_id', $includedUserIds)->delete();
        $round->pickups()->whereNotIn('user_id', $includedUserIds)->whereNull('picked_up_at')->delete();

        foreach ($totals->perParticipant as $participantTotals) {
            Payment::updateOrCreate(
                ['round_id' => $round->id, 'user_id' => $participantTotals->userId],
                [
                    'amount_cents' => $participantTotals->subtotalCents(),
                    'round_up_donation_cents' => $participantTotals->roundUpDonationCents,
                    'status' => PaymentStatus::Pending,
                    'paid_at' => null,
                ],
            );

            Pickup::firstOrCreate(['round_id' => $round->id, 'user_id' => $participantTotals->userId]);
        }
    }

    private function isVotingPhase(Round $round): bool
    {
        return in_array($round->phase, [RoundPhase::Negotiating, RoundPhase::Finalizing], true);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['proposal' => $message]);
        }
    }
}
