<?php

namespace App\Services\Rounds;

use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Models\OrderProposal;
use App\Models\Round;
use App\Models\User;
use App\Services\Proposals\ProposalBuilder;
use App\Services\Proposals\ProposalWorkflow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The last resort when several proposals fail because of single people:
 * while preparing a new version, the lead may exclude somebody from the
 * order. The person stays a group member and can join later rounds.
 * Drafts are recalculated without them; proposals that are up for a vote
 * and give them something can't change anymore and are withdrawn.
 */
class ParticipantExclusion
{
    /**
     * People are excluded while a new proposal version is prepared — before
     * the final order is paid.
     *
     * @var array<int, RoundPhase>
     */
    public const PHASES = [RoundPhase::Negotiating, RoundPhase::Finalizing];

    /**
     * Taking somebody back in also works after going back to shopping, so
     * they can adjust their cart.
     *
     * @var array<int, RoundPhase>
     */
    public const READMIT_PHASES = [RoundPhase::Shopping, RoundPhase::Negotiating, RoundPhase::Finalizing];

    public const MIN_REASON_LENGTH = 3;

    public function __construct(
        private ProposalWorkflow $workflow,
        private ProposalBuilder $builder,
        private ConsensusChecker $consensus,
    ) {}

    /**
     * @return int Number of proposals that were withdrawn.
     */
    public function exclude(Round $round, User $user, string $reason, User $by): int
    {
        $reason = trim($reason);
        $participant = $round->participantFor($user);

        $this->ensure(in_array($round->phase, self::PHASES, true), 'Ausschließen geht nur, während ein neuer Vorschlag vorbereitet wird — in der Anpassungs- oder Bestätigungsphase.');
        $this->ensure(! $round->isLead($user), 'Der Lead kann nicht ausgeschlossen werden.');
        $this->ensure($participant !== null && ! $participant->removed, 'Diese Person nimmt nicht (mehr) an der Runde teil.');
        $this->ensure(mb_strlen($reason) >= self::MIN_REASON_LENGTH, 'Bitte gib einen Grund für den Ausschluss an.');

        return DB::transaction(function () use ($round, $user, $reason, $by, $participant): int {
            $participant->update([
                'removed' => true,
                'remove_reason' => $reason,
                'removed_at' => now(),
                'removed_by_user_id' => $by->id,
            ]);

            $voted = $round->proposals()
                ->whereIn('status', [ProposalStatus::Published->value, ProposalStatus::Chosen->value])
                ->whereHas('allocations', fn ($query) => $query
                    ->where('proposal_allocations.user_id', $user->id)
                    ->where('proposal_allocations.quantity', '>', 0))
                ->get();
            $drafts = $round->proposals()->where('status', ProposalStatus::Draft->value)->get();

            $chosenProposalId = $round->newQuery()->whereKey($round->id)->value('chosen_proposal_id');

            if ($chosenProposalId !== null && $voted->contains('id', $chosenProposalId)) {
                $this->workflow->unchoose($round);
            }

            $voted->each(fn (OrderProposal $proposal) => $proposal->update(['status' => ProposalStatus::Withdrawn]));
            $drafts->each(fn (OrderProposal $draft) => $this->builder->recalculate($draft));

            $round->logActivity('participant_excluded', [
                'user_id' => $user->id,
                'name' => $user->fullName(),
                'reason' => $reason,
                'withdrawn_proposals' => $voted->pluck('title')->values()->all(),
            ]);

            return $voted->count();
        });
    }

    /**
     * Takes somebody back in. Drafts are recalculated with them again;
     * withdrawn proposals stay withdrawn.
     */
    public function readmit(Round $round, User $user, User $by): void
    {
        $participant = $round->participantFor($user);

        $this->ensure(in_array($round->phase, self::READMIT_PHASES, true), 'In dieser Phase kann niemand mehr aufgenommen werden.');
        $this->ensure($participant !== null && $participant->removed, 'Diese Person ist nicht ausgeschlossen.');

        DB::transaction(function () use ($round, $user, $participant): void {
            $participant->update([
                'removed' => false,
                'remove_reason' => null,
                'removed_at' => null,
                'removed_by_user_id' => null,
            ]);

            $round->proposals()
                ->where('status', ProposalStatus::Draft->value)
                ->get()
                ->each(fn (OrderProposal $draft) => $this->builder->recalculate($draft));

            $round->logActivity('participant_readmitted', [
                'user_id' => $user->id,
                'name' => $user->fullName(),
            ]);
        });
    }

    /**
     * Who may be excluded while preparing the given draft: people in it who
     * did not agree to an earlier proposal that was up for a vote — they
     * voted thumbs down or did not respond. The lead never.
     *
     * @return array<int, string> user id => what they did not agree to, latest proposal first
     */
    public function candidatesFor(OrderProposal $draft): array
    {
        $round = $draft->round;
        $inDraft = $draft->stakeholderIds()->all();
        $excludedIds = $round->participants()->where('removed', true)->pluck('user_id')->map(fn ($userId): int => (int) $userId)->all();
        $disagreements = [];

        $earlierVotes = $round->proposals()
            ->whereNotNull('published_at')
            ->whereIn('status', [ProposalStatus::Published->value, ProposalStatus::Withdrawn->value])
            ->with(['items.product', 'items.allocations', 'items.votes'])
            ->latest('published_at')
            ->get();

        foreach ($earlierVotes as $proposal) {
            foreach ($this->disagreementsWith($proposal) as $userId => $disagreement) {
                $disagreements[$userId][] = $disagreement;
            }
        }

        return collect($disagreements)
            ->filter(fn (array $reasons, int $userId): bool => in_array($userId, $inDraft, true)
                && $userId !== (int) $round->lead_user_id
                && ! in_array($userId, $excludedIds, true))
            ->map(fn (array $reasons): string => implode(' · ', $reasons))
            ->all();
    }

    /**
     * @return Collection<int, string> user id => thumbs down or missing answer
     */
    private function disagreementsWith(OrderProposal $proposal): Collection
    {
        $consensus = $this->consensus->evaluate($proposal);
        $rejectedProducts = [];

        foreach ($consensus->rejections() as $userId => $reasonsByItem) {
            $rejectedProducts[$userId] = collect(array_keys($reasonsByItem))
                ->map(fn (int $itemId): ?string => $proposal->items->firstWhere('id', $itemId)?->product?->name)
                ->filter()
                ->unique()
                ->implode(', ');
        }

        $disagreements = collect($rejectedProducts)
            ->map(fn (string $products): string => "👎 {$products} in „{$proposal->title}“");

        foreach ($consensus->pendingUserIds() as $userId) {
            if (! $disagreements->has($userId)) {
                $disagreements->put($userId, "keine Rückmeldung zu „{$proposal->title}“");
            }
        }

        return $disagreements;
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['participant' => $message]);
        }
    }
}
