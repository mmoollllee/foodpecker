<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Rounds\RoundResource;
use App\Models\Group;
use App\Models\Round;
use App\Models\User;
use App\Services\Rounds\OrderHistory;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The personal order history: what somebody received in which round and
 * what they paid for it — with the prices frozen at the time of ordering.
 */
class MyOrders extends Page
{
    protected string $view = 'filament.pages.my-orders';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Meine Bestellungen';

    protected static string|\UnitEnum|null $navigationGroup = 'Bestellungen';

    protected static ?int $navigationSort = 3;

    public function getTitle(): string|Htmlable
    {
        return 'Meine Bestellungen';
    }

    public function getSubheading(): ?string
    {
        return 'Was du in den Runden dieser Gruppe bekommen und bezahlt hast — mit den Preisen zum Zeitpunkt der Bestellung.';
    }

    public function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        return [
            'orders' => $tenant instanceof Group && $user instanceof User
                ? app(OrderHistory::class)->ordersFor($user, $tenant)
                : collect(),
            'roundUrl' => fn (Round $round): string => RoundResource::getUrl('view', ['record' => $round]),
        ];
    }
}
