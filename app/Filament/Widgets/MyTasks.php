<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentStatus;
use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Filament\Pages\Members;
use App\Filament\Pages\MyCart;
use App\Filament\Resources\Rounds\RoundResource;
use App\Models\Group;
use App\Models\Round;
use App\Models\User;
use App\Services\Money\Money;
use App\Services\Rounds\ConsensusChecker;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * "Was steht für dich an?" — the next steps in the running round, in the own
 * draft and in the group. It comes first on the dashboard.
 */
class MyTasks extends Widget
{
    protected string $view = 'filament.widgets.my-tasks';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        if (! $tenant instanceof Group || ! $user instanceof User) {
            return ['tasks' => []];
        }

        $rounds = Round::query()
            ->where('group_id', $tenant->id)
            ->active()
            ->visibleTo($user)
            ->with(['participants', 'lead', 'group'])
            ->latest('updated_at')
            ->get();

        $tasks = [];

        if ($tenant->pending_owner_id === $user->id) {
            $tasks[] = $this->task('👑', "Owner-Rolle für „{$tenant->name}“ übernehmen?", ($tenant->owner?->fullName() ?? 'Der Owner').' möchte dir die Gruppe übergeben.', 'Annehmen oder ablehnen', Members::getUrl(), 'sky');
        }

        foreach ($rounds as $round) {
            if ($round->isExcluded($user)) {
                continue;
            }

            if ($round->pending_lead_user_id === $user->id) {
                $tasks[] = $this->task('🤝', "Lead-Rolle für „{$round->title}“ übernehmen?", ($round->lead?->fullName() ?? 'Der Lead').' möchte dir die Runde übergeben.', 'Annehmen oder ablehnen', RoundResource::getUrl('view', ['record' => $round]), 'sky');
            }

            array_push($tasks, ...$this->tasksFor($round, $user));
        }

        return ['tasks' => $tasks];
    }

    /**
     * @return array<int, array{icon: string, title: string, description: string, cta: string, url: string, color: string}>
     */
    private function tasksFor(Round $round, User $user): array
    {
        $isLead = $round->isManagedBy($user);
        $url = RoundResource::getUrl('view', ['record' => $round]);

        return match ($round->phase) {
            RoundPhase::Draft => $isLead ? [$this->draftTask($round, $url)] : [],
            RoundPhase::Shopping => $this->shoppingTasks($round, $user, $isLead, $url),
            RoundPhase::Negotiating => $isLead ? [$this->task('📞', "Verhandlung für „{$round->title}“ abschließen", 'Preise beim Hersteller einholen, Vorschlag anpassen und zur Abstimmung freigeben.', 'Zur Runde', $url.'?tab=proposals', 'warning')] : [],
            RoundPhase::Finalizing => $this->votingTasks($round, $user, $isLead, $url),
            RoundPhase::Payment => $this->paymentTasks($round, $user, $isLead, $url),
            RoundPhase::Ordering => $isLead ? [$this->task('📦', "Bestellung für „{$round->title}“ aufgeben", 'Alle haben bezahlt — jetzt beim Hersteller bestellen und danach „Weiter zu: Lieferung“.', 'Zur Runde', $url, 'amber')] : [],
            RoundPhase::Pickup => $this->pickupTasks($round, $user, $url),
            default => [],
        };
    }

    /**
     * A group runs one round at a time: a draft waits until the running
     * round is over.
     *
     * @return array<string, string>
     */
    private function draftTask(Round $round, string $url): array
    {
        $running = $round->group->runningRound();

        if ($running === null) {
            return $this->task('📝', "Entwurf „{$round->title}“ starten", 'Abholort und mindestens einen Abholtermin eintragen, dann die Einkaufsphase eröffnen.', 'Zur Runde', $url, 'amber');
        }

        return $this->task('📝', "Entwurf „{$round->title}“ ist vorbereitet", "Starten kannst du ihn, sobald „{$running->title}“ abgeschlossen ist — es läuft immer nur eine Runde.", 'Zum Entwurf', $url, 'gray');
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function shoppingTasks(Round $round, User $user, bool $isLead, string $url): array
    {
        $tasks = [];

        if ($user->can('shop', $round) && ! $round->cartItems()->where('user_id', $user->id)->exists()) {
            $tasks[] = $this->task('🛒', "Warenkorb für „{$round->title}“ füllen", 'Die Einkaufsphase ist offen'.($round->shopping_deadline ? ' bis '.$round->shopping_deadline->format('d.m.Y') : '').'. Trag deine Wünsche ein — exakt oder als flexible Spanne.', 'Zum Warenkorb', MyCart::getUrl(), 'amber');
        }

        if ($isLead && $round->shopping_deadline?->isPast()) {
            $tasks[] = $this->task('⏰', "Einkaufsphase von „{$round->title}“ ist abgelaufen", 'Wenn alle fertig sind: „Weiter zu: Verhandlung“.', 'Zur Runde', $url, 'warning');
        }

        return $tasks;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function votingTasks(Round $round, User $user, bool $isLead, string $url): array
    {
        $tasks = [];
        $checker = app(ConsensusChecker::class);

        foreach ($round->proposals()->where('status', ProposalStatus::Published->value)->get() as $proposal) {
            $consensus = $checker->evaluate($proposal);
            $pendingForMe = collect($consensus->items)->filter(fn ($item): bool => in_array($user->id, $item->pendingIds, true))->count();

            if ($pendingForMe > 0) {
                $tasks[] = $this->task('🤝', "{$pendingForMe} offene Abstimmung(en) in „{$proposal->title}“", "Runde „{$round->title}“ — gib pro Position einen Daumen.", 'Zur Abstimmung', $url.'?tab=proposals', 'sky');
            }

            if ($isLead && $consensus->isUnanimous()) {
                $tasks[] = $this->task('✅', "„{$proposal->title}“ ist einstimmig", 'Jetzt als finale Bestellung wählen und in die Zahlungsphase wechseln.', 'Zur Runde', $url.'?tab=proposals', 'success');
            } elseif ($isLead && $consensus->rejections() !== []) {
                $tasks[] = $this->task('✋', "„{$proposal->title}“ wird blockiert", 'Lies die Begründungen und erstelle eine neue Version, die für alle passt.', 'Zur Runde', $url.'?tab=proposals', 'warning');
            }
        }

        return $tasks;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function paymentTasks(Round $round, User $user, bool $isLead, string $url): array
    {
        $tasks = [];
        $myPayment = $round->payments()->where('user_id', $user->id)->first();

        if ($myPayment?->status === PaymentStatus::Pending) {
            $tasks[] = $this->task('💶', "Anteil für „{$round->title}“ überweisen", Money::format($myPayment->totalCents()).' an '.($round->lead?->fullName() ?? 'den Lead').($round->payment_deadline ? ' bis '.$round->payment_deadline->format('d.m.Y') : '').'.', 'Details', $url.'?tab=fulfillment', 'danger');
        }

        if ($isLead) {
            $pending = $round->payments()->where('status', PaymentStatus::Pending->value)->count();

            if ($pending > 0) {
                $tasks[] = $this->task('✅', "{$pending} Zahlung(en) für „{$round->title}“ abhaken", 'Markiere eingegangene Überweisungen als bezahlt.', 'Zahlungen', $url.'?tab=fulfillment', 'amber');
            }
        }

        return $tasks;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function pickupTasks(Round $round, User $user, string $url): array
    {
        $myPickup = $round->pickups()->where('user_id', $user->id)->first();

        if ($myPickup === null || $myPickup->isPickedUp()) {
            return [];
        }

        return [$this->task(
            '📦',
            "Ware aus „{$round->title}“ abholen",
            $myPickup->pickup_date_id ? ($round->pickup_location ?? 'Beim Lead abholen.') : 'Wähl noch einen Abholtermin.',
            'Termine',
            $url.'?tab=fulfillment',
            'success',
        )];
    }

    /**
     * @return array{icon: string, title: string, description: string, cta: string, url: string, color: string}
     */
    private function task(string $icon, string $title, string $description, string $cta, string $url, string $color): array
    {
        return compact('icon', 'title', 'description', 'cta', 'url', 'color');
    }
}
