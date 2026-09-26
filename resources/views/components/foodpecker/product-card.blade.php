@props([
    /** @var \App\Models\Product|null $product */
    'product' => null,
    /** Compact-Modus: nur Header + Tags, keine Preisstaffeln/Beschreibung */
    'compact' => false,
    /** Beschreibung anzeigen? */
    'showDescription' => true,
    /** Preis-Sektion anzeigen? */
    'showPricing' => true,
])

@php
    use App\Enums\ProductCategory;
    use App\Enums\Visibility;

    /** @var \App\Models\Product|null $product */
    if (! $product) {
        return;
    }

    $product->loadMissing('supplier', 'priceTiers');
    $category = $product->category;
    $visibility = $product->visibility;

    $unitLabel = $product->unitLabel();
    $currentGroup = \Filament\Facades\Filament::getTenant();
    $observations = $showPricing && ! $compact
        ? $product->recentPriceObservations($currentGroup instanceof \App\Models\Group ? $currentGroup : null)
        : collect();

    $imageUrl = $product->imageUrl();

    // Kategorie-spezifische Farben für den Fallback-Hintergrund + Akzent
    [$bgFrom, $bgTo] = match ($category) {
        ProductCategory::Grains => ['#fbbf24', '#b45309'],
        ProductCategory::Flours => ['#fde68a', '#92400e'],
        ProductCategory::Pasta => ['#fb923c', '#9a3412'],
        ProductCategory::Legumes => ['#a3e635', '#3f6212'],
        ProductCategory::Condiments => ['#fb7185', '#9f1239'],
        ProductCategory::Oils => ['#34d399', '#065f46'],
        ProductCategory::Produce => ['#86efac', '#166534'],
        ProductCategory::Sweeteners => ['#f9a8d4', '#9d174d'],
        ProductCategory::Spices => ['#f87171', '#991b1b'],
        ProductCategory::Dairy => ['#7dd3fc', '#075985'],
        ProductCategory::Beverages => ['#93c5fd', '#1e40af'],
        default => ['#d4d4d8', '#52525b'],
    };
@endphp

<div class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/5 overflow-hidden">
    {{-- Header mit Bild links --}}
    <div class="flex items-stretch gap-0">
        {{-- Produktbild oder Fallback --}}
        <div class="shrink-0 w-28 sm:w-32 relative overflow-hidden"
             style="background: linear-gradient(135deg, {{ $bgFrom }} 0%, {{ $bgTo }} 100%);">
            @if ($imageUrl)
                <img src="{{ $imageUrl }}" alt="{{ $product->name }}"
                     class="absolute inset-0 w-full h-full object-cover"
                     loading="lazy" />
            @else
                <div class="absolute inset-0 flex items-center justify-center">
                    <div class="text-3xl sm:text-4xl font-bold text-white/95 tracking-wide drop-shadow">
                        {{ $product->initials() }}
                    </div>
                </div>
            @endif
            {{-- subtle dot pattern overlay --}}
            <div class="absolute inset-0 opacity-10"
                 style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 12px 12px;"></div>
        </div>

        {{-- Header-Texte rechts --}}
        <div class="flex-1 min-w-0 px-4 py-3">
            <div class="flex items-start justify-between gap-3">
                <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-base sm:text-lg text-gray-900 dark:text-gray-50 leading-tight">
                        {{ $product->name }}
                    </h3>
                    @if ($product->supplier)
                        <div class="mt-0.5 text-sm text-gray-500 dark:text-gray-400 truncate">
                            {{ $product->supplier->name }}
                        </div>
                    @endif
                </div>
                @if ($visibility instanceof Visibility)
                    <span @class([
                        'shrink-0 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                        'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $visibility === Visibility::Public,
                        'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' => $visibility === Visibility::Private,
                    ])>
                        {{ $visibility->getLabel() }}
                    </span>
                @endif
            </div>

            {{-- Tag-Reihe --}}
            <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                @if ($category instanceof ProductCategory)
                    <span @class([
                        'inline-flex items-center rounded-full px-2 py-0.5 font-medium',
                        'bg-amber-500/10 text-amber-700 dark:text-amber-300'   => in_array($category, [ProductCategory::Grains, ProductCategory::Flours], true),
                        'bg-orange-500/10 text-orange-700 dark:text-orange-300' => $category === ProductCategory::Pasta,
                        'bg-lime-500/10 text-lime-700 dark:text-lime-300'      => $category === ProductCategory::Legumes,
                        'bg-rose-500/10 text-rose-700 dark:text-rose-300'      => $category === ProductCategory::Condiments,
                        'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $category === ProductCategory::Oils,
                        'bg-pink-500/10 text-pink-700 dark:text-pink-300'      => $category === ProductCategory::Sweeteners,
                        'bg-red-500/10 text-red-700 dark:text-red-300'         => $category === ProductCategory::Spices,
                        'bg-sky-500/10 text-sky-700 dark:text-sky-300'         => $category === ProductCategory::Dairy,
                        'bg-blue-500/10 text-blue-700 dark:text-blue-300'      => $category === ProductCategory::Beverages,
                        'bg-green-500/10 text-green-700 dark:text-green-300'   => $category === ProductCategory::Produce,
                        'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300' => $category === ProductCategory::Other,
                    ])>{{ $category->getLabel() }}</span>
                @endif
                <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-white/5 px-2 py-0.5 font-medium text-gray-700 dark:text-gray-300">
                    {{ $unitLabel }}
                </span>
                <span class="inline-flex items-center rounded-full bg-indigo-500/10 px-2 py-0.5 font-medium text-indigo-700 dark:text-indigo-300">
                    {{ $product->distributionLabel() }}
                </span>
            </div>
        </div>
    </div>

    @if (! $compact)
        {{-- Beschreibung — direkt nach Header für hohe Sichtbarkeit --}}
        @if ($showDescription && filled($product->description))
            <div class="px-4 py-3 border-t border-gray-100 dark:border-white/5 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                {{ $product->description }}
            </div>
        @endif

        {{-- Preisstaffeln --}}
        @if ($showPricing && $product->priceTiers->isNotEmpty())
            <div class="px-4 py-3 border-t border-gray-100 dark:border-white/5">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">
                    Gebindegrößen & Preise
                </div>
                <div class="space-y-1.5">
                    @foreach ($product->priceTiers as $tier)
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <div class="flex items-center gap-2 min-w-0 flex-wrap">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $tier->label }}</span>
                                @if ($tier->article_number)
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Art.-Nr. {{ $tier->article_number }}</span>
                                @endif
                                @if ($tier->min_order_packages > 1)
                                    <span class="inline-flex items-center rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-400">
                                        ab {{ $tier->min_order_packages }} Stück
                                    </span>
                                @endif
                            </div>
                            <div class="text-right shrink-0">
                                <div class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $tier->formattedPrice() }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 tabular-nums">{{ $tier->formattedPricePerUnit() }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Tatsächlich gezahlte Preise aus abgeschlossenen Bestellungen --}}
        @if ($observations->isNotEmpty())
            <div class="px-4 py-3 border-t border-gray-100 dark:border-white/5">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">
                    Zuletzt tatsächlich bezahlt
                </div>
                <div class="space-y-1">
                    @foreach ($observations as $observation)
                        <div class="flex items-center justify-between gap-3 text-xs">
                            <span class="text-gray-600 dark:text-gray-300">
                                {{ $observation->observed_on->format('m/Y') }}
                                · {{ \App\Models\CartItem::formatAmount((float) $observation->package_amount, $unitLabel) }}
                                · {{ $observation->group_id === $currentGroup?->getKey() ? 'eure Gruppe' : 'andere Gruppe' }}
                            </span>
                            <span class="tabular-nums font-medium text-gray-900 dark:text-gray-100">
                                {{ \App\Services\Money\Money::format($observation->observed_price_cents) }}
                                <span class="font-normal text-gray-500">({{ number_format($observation->pricePerUnitCents() / 100, 2, ',', '.') }} € / {{ $unitLabel }})</span>
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

    @endif
</div>
