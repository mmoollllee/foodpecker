@php
    /** @var \App\Models\Round $round */
    $suppliers = $this->orderSuppliers();
    $records = $round->roundSuppliers->keyBy('supplier_id');
    $ordered = $suppliers->filter(fn ($supplier): bool => (bool) $records->get($supplier->id)?->isOrdered())->count();
    $delivered = $suppliers->filter(fn ($supplier): bool => (bool) $records->get($supplier->id)?->isDelivered())->count();
@endphp

@if ($suppliers->isNotEmpty())
    <div class="mt-4 flex flex-wrap gap-2 text-xs">
        <span @class([
            'inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-medium',
            'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $ordered === $suppliers->count(),
            'bg-amber-500/10 text-amber-700 dark:text-amber-300' => $ordered < $suppliers->count(),
        ])>Bestellt {{ $ordered }}/{{ $suppliers->count() }}</span>
        <span @class([
            'inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-medium',
            'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $delivered === $suppliers->count(),
            'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300' => $delivered < $suppliers->count(),
        ])>Angekommen {{ $delivered }}/{{ $suppliers->count() }}</span>
    </div>
@endif
