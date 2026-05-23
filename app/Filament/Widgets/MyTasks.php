<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentStatus;
use App\Enums\ProposalStatus;
use App\Enums\RoundPhase;
use App\Filament\Resources\Rounds\RoundResource;
use App\Models\Group;
use App\Models\Round;
use App\Services\Money\Money;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * Was steht aktuell für mich an? — pro Phase eine Liste.
 */
class MyTasks extends Widget
{
    protected string $view = 'filament.widgets.my-tasks';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        if (! $tenant instanceof Group || ! $user) {
            return ['tasks' => []];
        }

        $tasks = [];

        $myActiveRounds = Round::query()
            ->where('group_id', $tenant->id)
            ->whereNotIn('phase', [RoundPhase::Completed->value, RoundPhase::Cancelled->value])
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id)->where('removed', false))
            ->orWhereHas('participants', fn ($q) => $q)
            ->latest('updated_at')
            ->get();

        foreach ($myActiveRounds as $round) {
            $participant = $round->participants->firstWhere('user_id', $user->id);
            $isLead = $round->lead_user_id === $user->id;

            switch ($round->phase) {
                case RoundPhase::Shopping:
                    $cartCount = $round->cartItems()->where('user_id', $user->id)->count();
                    if ($cartCount === 0) {
                        $tasks[] = [
                            'round' => $round,
                            'icon' => '🛒',
                            'title' => "Warenkorb für {$round->title} füllen",
                            'description' => 'Die Einkaufsphase ist offen. Trag deine Wünsche ein — exakt oder als flexible Spanne.',
                            'cta' => 'Zur Runde',
                            'url' => RoundResource::getUrl('view', ['record' => $round]),
                            'color' => 'amber',
                        ];
                    }
                    break;

                case RoundPhase::Finalizing:
                    $publishedProposals = $round->proposals()
                        ->where('status', ProposalStatus::Published->value)
                        ->orWhere('status', ProposalStatus::Chosen->value)
                        ->get();

                    foreach ($publishedProposals as $proposal) {
                        $unvotedItems = $proposal->items()
                            ->whereDoesntHave('votes', fn ($q) => $q->where('user_id', $user->id))
                            ->count();
                        if ($unvotedItems > 0) {
                            $tasks[] = [
                                'round' => $round,
                                'icon' => '🤝',
                                'title' => "{$unvotedItems} offene Abstimmungen in {$proposal->title}",
                                'description' => "Vorschlag in {$round->title} — gib pro Position einen Daumen.",
                                'cta' => 'Zur Abstimmung',
                                'url' => RoundResource::getUrl('view', ['record' => $round]),
                                'color' => 'sky',
                            ];
                            break; // Nur eine Aufgabe pro Runde
                        }
                    }
                    break;

                case RoundPhase::Payment:
                    $myPayment = $round->payments()->where('user_id', $user->id)->first();
                    if ($myPayment && $myPayment->status === PaymentStatus::Pending) {
                        $tasks[] = [
                            'round' => $round,
                            'icon' => '💶',
                            'title' => "Anteil für {$round->title} überweisen",
                            'description' => 'Insgesamt '.Money::format($myPayment->totalCents()).' an '.($round->lead?->fullName() ?? 'den Lead').' überweisen.',
                            'cta' => 'Details',
                            'url' => RoundResource::getUrl('view', ['record' => $round]),
                            'color' => 'danger',
                        ];
                    }
                    if ($isLead) {
                        $pendingCount = $round->payments()->where('status', PaymentStatus::Pending->value)->count();
                        if ($pendingCount > 0) {
                            $tasks[] = [
                                'round' => $round,
                                'icon' => '✅',
                                'title' => "{$pendingCount} Zahlungen für {$round->title} abhaken",
                                'description' => 'Als Lead: markiere eingegangene Überweisungen.',
                                'cta' => 'Zahlungen',
                                'url' => RoundResource::getUrl('view', ['record' => $round]),
                                'color' => 'amber',
                            ];
                        }
                    }
                    break;

                case RoundPhase::Pickup:
                    $myPickup = $round->pickups()->where('user_id', $user->id)->first();
                    if ($myPickup && ! $myPickup->isPickedUp()) {
                        $tasks[] = [
                            'round' => $round,
                            'icon' => '📦',
                            'title' => "Ware aus {$round->title} abholen",
                            'description' => $round->pickup_location ?? 'Beim Lead abholen.',
                            'cta' => 'Termine',
                            'url' => RoundResource::getUrl('view', ['record' => $round]),
                            'color' => 'success',
                        ];
                    }
                    break;

                default:
                    if ($isLead && $round->phase === RoundPhase::Negotiating) {
                        $tasks[] = [
                            'round' => $round,
                            'icon' => '📞',
                            'title' => "Verhandlung {$round->title} abschließen",
                            'description' => 'Als Lead: Preise einholen und Vorschlag veröffentlichen.',
                            'cta' => 'Zur Runde',
                            'url' => RoundResource::getUrl('view', ['record' => $round]),
                            'color' => 'warning',
                        ];
                    }
                    break;
            }
        }

        return ['tasks' => $tasks];
    }
}
