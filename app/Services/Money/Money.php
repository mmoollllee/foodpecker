<?php

namespace App\Services\Money;

/**
 * Mini-Helper für Geldrechnungen.
 *
 * Alle Beträge in Integer-Cent. Prozente werden über Basispunkte gerechnet
 * (1 % = 100 bps), um Floating-Point-Drift zu vermeiden.
 */
final class Money
{
    public static function percent(int $cents, float $percent): int
    {
        $bps = (int) round($percent * 100);

        return intdiv($cents * $bps, 10_000);
    }

    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.').' €';
    }

    /**
     * Formats cents for an input field in German notation without currency
     * symbol and without thousands separator (e.g. 123456 → "1234,56").
     */
    public static function toInputString(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Parses a user-entered euro amount into cents without floating point.
     *
     * Accepts German and English notation: "12", "12,5", "12,50", "1.234,56",
     * "1234.56", "12 €". A dot followed by exactly three digits is treated as
     * thousands separator ("1.234" → 123400). Returns null for blank or
     * unparseable input.
     */
    public static function parse(string|int|float|null $input): ?int
    {
        if ($input === null) {
            return null;
        }

        if (is_int($input)) {
            return $input * 100;
        }

        $value = str_replace(['€', ' ', "\u{00A0}"], '', trim((string) $input));

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $value) === 1) {
            $value = str_replace('.', '', $value);
        }

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            return null;
        }

        $euros = (int) $matches[1];
        $cents = (int) str_pad($matches[2] ?? '0', 2, '0');

        return $euros * 100 + $cents;
    }

    /**
     * Gleichmäßige Aufteilung mit Rest-Verteilung auf die ersten N Empfänger.
     *
     * @return array<int, int>
     */
    public static function splitEvenly(int $cents, int $shares): array
    {
        if ($shares <= 0) {
            return [];
        }
        $base = intdiv($cents, $shares);
        $remainder = $cents - ($base * $shares);
        $result = array_fill(0, $shares, $base);
        for ($i = 0; $i < $remainder; $i++) {
            $result[$i]++;
        }

        return $result;
    }

    /**
     * Aufrunden auf das nächste Vielfache von `stepCents`.
     */
    public static function roundUpTo(int $cents, int $stepCents): int
    {
        if ($stepCents <= 0) {
            return $cents;
        }
        $remainder = $cents % $stepCents;

        return $remainder === 0 ? $cents : $cents + ($stepCents - $remainder);
    }
}
