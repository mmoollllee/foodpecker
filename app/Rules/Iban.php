<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * An IBAN whose check digits add up. Spaces and lower case are fine —
 * {@see normalize()} takes them out before saving.
 */
class Iban implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid((string) $value)) {
            $fail('Das ist keine gültige IBAN — bitte noch einmal prüfen.');
        }
    }

    /**
     * "de89 3704 0044 0532 0130 00" becomes "DE89370400440532013000".
     */
    public static function normalize(?string $iban): ?string
    {
        // Unicode-aware, so non-breaking spaces from banking pages go, too.
        $normalized = strtoupper((string) preg_replace('/\s+/u', '', (string) $iban));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Country code, check digits and account — moved to the end and read as
     * a number (A = 10 … Z = 35), it leaves a remainder of 1 when divided
     * by 97.
     */
    public static function isValid(string $iban): bool
    {
        $iban = self::normalize($iban) ?? '';

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }

        $digits = (string) preg_replace_callback(
            '/[A-Z]/',
            fn (array $letter): string => (string) (ord($letter[0]) - 55),
            substr($iban, 4).substr($iban, 0, 4),
        );

        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }
}
