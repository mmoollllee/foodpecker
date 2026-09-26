<?php

namespace App\Services\Money;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * The GiroCode (EPC QR code) of a SEPA transfer: banking apps read the
 * recipient, IBAN, amount and reference from it. The QR library comes with
 * Filament, which uses it for two-factor sign-in.
 */
class GiroCode
{
    /**
     * The text inside the code, after the EPC standard. Version 002 needs
     * no BIC within the SEPA area.
     */
    public function payload(string $name, string $iban, int $amountCents, string $reference, ?string $bic = null): string
    {
        return implode("\n", [
            'BCD',
            '002',
            '1',
            'SCT',
            (string) $bic,
            mb_substr($name, 0, 70),
            $iban,
            $amountCents > 0 ? 'EUR'.number_format($amountCents / 100, 2, '.', '') : '',
            '',
            '',
            mb_substr($reference, 0, 140),
        ]);
    }

    /**
     * The code as an SVG data URI for an <img>.
     */
    public function dataUri(string $name, string $iban, int $amountCents, string $reference, ?string $bic = null): string
    {
        $options = new QROptions([
            'eccLevel' => EccLevel::M,
            'outputBase64' => true,
            'svgAddXmlHeader' => false,
        ]);

        return (new QRCode($options))->render($this->payload($name, $iban, $amountCents, $reference, $bic));
    }
}
