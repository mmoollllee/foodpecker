@php
    use App\Models\CartItem;

    /** @var \App\Models\Round $round */
    /** @var \Illuminate\Support\Collection $people */
    /** @var \Illuminate\Support\Collection $products */
    $quantity = fn (float $value, string $unit): string => CartItem::formatAmount($value, $unit);
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Packliste · {{ $round->title }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0 auto; max-width: 820px; padding: 24px 16px; background: #fff; color: #111827; font: 14px/1.45 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 20px; }
        h1 { margin: 0; font-size: 20px; }
        h2 { margin: 28px 0 8px; font-size: 16px; }
        .muted { color: #6b7280; font-size: 12px; }
        .print { padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 8px; background: #f9fafb; font: inherit; cursor: pointer; }
        .person { margin-bottom: 12px; padding: 12px 14px; border: 1px solid #d1d5db; border-radius: 8px; break-inside: avoid; }
        .person-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 12px; }
        .person-name { font-size: 16px; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 5px 0; border-top: 1px solid #e5e7eb; vertical-align: top; text-align: left; }
        th { font-size: 12px; font-weight: 600; color: #6b7280; }
        .person table { margin-top: 8px; }
        .check { width: 26px; }
        .box { display: inline-block; width: 14px; height: 14px; margin-top: 2px; border: 1.5px solid #374151; border-radius: 3px; }
        .qty { padding-left: 12px; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .done { color: #047857; }
        @media print {
            body { max-width: none; padding: 0; }
            .print { display: none; }
            @page { margin: 12mm; }
        }
    </style>
</head>
<body>
    <header>
        <div>
            <h1>Packliste · {{ $round->title }}</h1>
            <div class="muted">
                {{ $round->group?->name }} · Lead: {{ $round->lead?->fullName() ?? '—' }} · Stand {{ now()->format('d.m.Y H:i') }}
            </div>
            @if ($round->pickup_location)
                <div class="muted">Abholort: {{ $round->pickup_location }}</div>
            @endif
        </div>
        <button type="button" class="print" onclick="window.print()">Drucken</button>
    </header>

    @forelse ($people as $person)
        <section class="person" aria-label="{{ $person['user']?->fullName() }}">
            <div class="person-head">
                <span class="person-name">{{ $person['user']?->fullName() ?? '—' }}</span>
                <span class="muted">
                    @if ($person['pickup']?->isPickedUp())
                        <span class="done">✓ abgeholt {{ $person['pickup']->picked_up_at->format('d.m.') }}</span>
                    @elseif ($person['pickup']?->pickupDate)
                        Abholung {{ $person['pickup']->pickupDate->label() }}
                    @else
                        noch kein Abholtermin
                    @endif
                </span>
            </div>
            @if ($person['user']?->phone)
                <div class="muted">{{ $person['user']->phone }}</div>
            @endif

            <table>
                <tbody>
                    @foreach ($person['lines'] as $line)
                        <tr>
                            <td class="check"><span class="box"></span></td>
                            <td>
                                {{ $line['product'] }}
                                <div class="muted">{{ $line['portioned'] ? 'aus '.$line['packages'] : $line['packages'] }}</div>
                            </td>
                            <td class="qty">{{ $quantity($line['quantity'], $line['unit']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @empty
        <p class="muted">Aus der finalen Bestellung bekommt niemand etwas.</p>
    @endforelse

    @if ($products->isNotEmpty())
        <h2>Zum Aufteilen</h2>
        <table>
            <thead>
                <tr>
                    <th>Produkt</th>
                    <th>Bestellt</th>
                    <th>Aufteilung</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($products as $product)
                    <tr>
                        <td>{{ $product['product'] }}</td>
                        <td>
                            {{ $quantity($product['total'], $product['unit']) }}
                            <div class="muted">{{ $product['packages'] }}</div>
                        </td>
                        <td>
                            {{ $product['shares']->map(fn (array $share): string => $share['name'].' '.$quantity($share['quantity'], $product['unit']))->implode(' · ') }}
                            @if ($product['leftover'] > 0.001)
                                <div class="muted">übrig: {{ $quantity($product['leftover'], $product['unit']) }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
