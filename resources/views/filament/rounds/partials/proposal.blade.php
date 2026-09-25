@php
    use App\Enums\ProposalStatus;
    use App\Enums\VoteValue;
    use App\Models\CartItem;
    use App\Services\Money\Money;
    use App\Services\Proposals\ProposalWorkflow;

    /** @var \App\Models\OrderProposal $proposal */
    $round = $this->getRound();
    $currentUserId = $this->currentUser()->id;
    $consensus = $this->consensusFor($proposal);
    $totals = $this->totalsFor($proposal);
    $shares = collect($totals->perParticipant)->keyBy('userId');
    $isVotedOn = in_array($proposal->status, [ProposalStatus::Published, ProposalStatus::Chosen], true);
    $canVoteHere = $proposal->isPublished() && $this->currentUser()->can('vote', $round);
    $stakeholderIds = $proposal->items->flatMap(fn ($item) => $item->stakeholderIds())->unique();

    // One column per person: you first, then everybody else who takes part or gets something.
    $columns = $round->participants
        ->filter(fn ($participant) => ! $participant->removed || $stakeholderIds->contains((int) $participant->user_id))
        ->sortBy(fn ($participant) => [(int) $participant->user_id !== $currentUserId, $participant->user?->first_name])
        ->values();

    $percent = fn ($value): string => number_format((float) $value, 1, ',', '.').' %';
    $costRows = [
        'Waren' => [$totals->goodsCents, fn ($share) => $share->goodsCents],
        'Versand' => [$totals->shippingCents, fn ($share) => $share->shippingShareCents],
        'Aufwandsentschädigung Lead ('.$percent($round->lead_fee_percent).')' => [$totals->leadFeeCents, fn ($share) => $share->leadFeeCents],
        'Vereinsbeitrag ('.$percent($round->platform_fee_percent).')' => [$totals->platformFeeCents, fn ($share) => $share->platformFeeCents],
    ];

    $stickyCell = 'sticky left-0 z-10 bg-white dark:bg-gray-900';
@endphp

<x-filament::section
    :heading="$proposal->title"
    :description="'Vorgeschlagen von '.($proposal->proposedBy?->fullName() ?? '—').($proposal->published_at ? ' · freigegeben '.$proposal->published_at->diffForHumans() : '')"
>
    <x-slot name="afterHeader">
        <div class="flex items-center gap-3">
            <x-filament::badge :color="$proposal->status->getColor()">{{ $proposal->status->getLabel() }}</x-filament::badge>
            <span class="text-sm font-semibold tabular-nums">{{ Money::format($totals->grandTotalCents) }}</span>
        </div>
    </x-slot>

    <div class="space-y-4">
        @if ($proposal->description)
            <p class="whitespace-pre-line text-sm text-gray-600 dark:text-gray-400">{{ $proposal->description }}</p>
        @endif

        @if ($proposal->status === ProposalStatus::Chosen)
            <div class="rounded-lg bg-emerald-500/10 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">✅ Das ist die finale Bestellung — alle Beteiligten haben zugestimmt.</div>
        @elseif ($proposal->isPublished())
            @if ($consensus->isUnanimous())
                <div class="rounded-lg bg-emerald-500/10 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">✅ Einstimmig — alle Beteiligten haben zugestimmt.</div>
            @else
                <div class="rounded-lg bg-amber-500/10 px-3 py-2 text-sm text-amber-800 dark:text-amber-200">
                    {{ app(ProposalWorkflow::class)->explainMissingConsensus($consensus) }}
                </div>
            @endif
        @elseif ($proposal->isProposedBy($this->currentUser()) || $this->canManage())
            <p class="text-sm text-gray-500">Entwurf — pass Gebinde und Preise pro Position an und gib ihn dann zur Abstimmung frei.</p>
        @else
            <p class="text-sm text-gray-500">Entwurf — wird noch vorbereitet. Abgestimmt wird, sobald er freigegeben ist.</p>
        @endif

        <div class="flex flex-wrap items-center gap-2 [&:not(:has(*))]:hidden">
            <x-foodpecker.action :action="($this->chooseProposalAction)(['proposal' => $proposal->id])" />
            <x-foodpecker.action :action="($this->publishProposalAction)(['proposal' => $proposal->id])" />
            <x-foodpecker.action :action="($this->editProposalAction)(['proposal' => $proposal->id])" />
            <x-foodpecker.action :action="($this->newProposalVersionAction)(['proposal' => $proposal->id])" />
            <x-foodpecker.action :action="($this->withdrawProposalAction)(['proposal' => $proposal->id])" />
            <x-foodpecker.action :action="($this->deleteProposalAction)(['proposal' => $proposal->id])" />
            <x-foodpecker.action :action="$this->draftMenu($proposal)" />
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 dark:border-white/10">
                    <tr class="text-left">
                        <th class="{{ $stickyCell }} min-w-48 py-2 pe-4 font-semibold">Position</th>
                        <th class="px-3 py-2 text-end font-semibold">Gesamt</th>
                        @foreach ($columns as $participant)
                            @php $isMe = (int) $participant->user_id === $currentUserId; @endphp
                            <th @class(['px-3 py-2 text-center font-semibold', 'bg-primary-500/5' => $isMe]) title="{{ $participant->user?->fullName() }}">
                                <span @class(['line-through text-gray-400' => $participant->removed])>{{ $participant->user?->first_name ?? '—' }}</span>
                                @if ($isMe)
                                    <span class="block text-xs font-normal text-gray-500">du</span>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($proposal->items as $item)
                        @php
                            $itemConsensus = $consensus->forItem($item->id);
                            $unit = $item->product?->unitLabel();
                            $leftover = max(0, $item->totalQuantity() - (float) $item->allocations->sum('quantity'));
                        @endphp
                        <tr class="align-top">
                            <td class="{{ $stickyCell }} py-2 pe-4">
                                <div class="font-medium">{{ $item->product?->name }}</div>
                                <div class="text-xs text-gray-500">{{ $item->packages_ordered }} × {{ $item->packageLabel() }} à {{ Money::format((int) $item->package_price_cents) }}</div>
                                @if ($item->notes)
                                    <div class="mt-1 whitespace-pre-line text-xs text-amber-700 dark:text-amber-300">⚠️ {{ $item->notes }}</div>
                                @elseif ($leftover > 0.001)
                                    <div class="mt-1 text-xs text-amber-700 dark:text-amber-300">⚠️ {{ CartItem::formatQuantity($leftover) }} {{ $unit }} bleiben übrig</div>
                                @endif
                                <x-foodpecker.action :action="($this->editProposalItemAction)(['item' => $item->id])" />
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums">
                                <div>{{ CartItem::formatQuantity($item->totalQuantity()) }} {{ $unit }}</div>
                                <div class="text-xs text-gray-500">{{ Money::format((int) $item->total_price_cents) }}</div>
                            </td>
                            @foreach ($columns as $participant)
                                @php
                                    $userId = (int) $participant->user_id;
                                    $isMe = $userId === $currentUserId;
                                    $allocation = $item->allocations->first(fn ($allocation) => (int) $allocation->user_id === $userId && (float) $allocation->quantity > 0);
                                    $symbol = match (true) {
                                        ! $isVotedOn => '',
                                        in_array($userId, $itemConsensus?->approvedBy ?? [], true) => '👍',
                                        array_key_exists($userId, $itemConsensus?->rejectedBy ?? []) => '👎',
                                        $proposal->isPublished() => '⏳',
                                        default => '',
                                    };
                                    $vote = $item->votes->firstWhere('user_id', $userId);
                                    $name = $participant->user?->first_name;
                                    $tooltip = match ($symbol) {
                                        '👍' => $name.' stimmt zu',
                                        '👎' => $name.': „'.($vote?->reason ?? '—').'“',
                                        '⏳' => $name.' hat noch nicht abgestimmt',
                                        default => null,
                                    };
                                    $opinion = $allocation ? null : $vote;
                                @endphp
                                <td @class(['whitespace-nowrap px-3 py-2 text-center', 'bg-primary-500/5' => $isMe])>
                                    @if ($allocation)
                                        <div class="tabular-nums">
                                            @unless ($isMe && $canVoteHere)
                                                <span @if ($tooltip) x-tooltip="{ content: @js($tooltip), theme: $store.theme }" @endif>{{ $symbol }}</span>
                                            @endunless
                                            {{ CartItem::formatQuantity((float) $allocation->quantity) }} {{ $unit }}
                                        </div>
                                        <div class="text-xs tabular-nums text-gray-500">{{ Money::format((int) $allocation->share_cents) }}</div>
                                    @else
                                        <span class="text-gray-300 dark:text-gray-600">—</span>
                                        @if ($opinion && ! ($isMe && $canVoteHere))
                                            <span
                                                class="text-xs opacity-60"
                                                x-tooltip="{ content: @js('Meinung von '.$name.' — zählt nicht, weil '.$name.' hiervon nichts bekommt'.($opinion->reason ? ': „'.$opinion->reason.'“' : '')), theme: $store.theme }"
                                            >{{ $opinion->value === VoteValue::Up ? '👍' : '👎' }}</span>
                                        @endif
                                    @endif

                                    @if ($isMe && $canVoteHere)
                                        <div @class([
                                            'mt-1 flex justify-center gap-1',
                                            'opacity-40 transition hover:opacity-100 focus-within:opacity-100' => ! $allocation,
                                        ])>
                                            <x-foodpecker.action :action="($this->voteUpAction)(['item' => $item->id])" />
                                            <x-foodpecker.action :action="($this->voteDownAction)(['item' => $item->id])" />
                                        </div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>

                <tfoot class="border-t border-gray-200 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                    @foreach ($costRows as $label => [$total, $shareOf])
                        <tr>
                            <th class="{{ $stickyCell }} py-1 pe-4 text-left font-normal">{{ $label }}</th>
                            <td class="whitespace-nowrap px-3 py-1 text-end tabular-nums">{{ Money::format($total) }}</td>
                            @foreach ($columns as $participant)
                                @php $share = $shares->get((int) $participant->user_id); @endphp
                                <td @class(['whitespace-nowrap px-3 py-1 text-center tabular-nums', 'bg-primary-500/5' => (int) $participant->user_id === $currentUserId])>
                                    {{ $share ? Money::format($shareOf($share)) : '—' }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr class="border-t border-gray-200 text-sm font-semibold text-gray-950 dark:border-white/10 dark:text-white">
                        <th class="{{ $stickyCell }} py-2 pe-4 text-left">Summe</th>
                        <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums">{{ Money::format($totals->grandTotalCents) }}</td>
                        @foreach ($columns as $participant)
                            @php $share = $shares->get((int) $participant->user_id); @endphp
                            <td @class(['whitespace-nowrap px-3 py-2 text-center tabular-nums', 'bg-primary-500/5' => (int) $participant->user_id === $currentUserId])>
                                {{ $share ? Money::format($share->subtotalCents()) : '—' }}
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</x-filament::section>
