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
