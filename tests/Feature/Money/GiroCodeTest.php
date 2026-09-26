<?php

use App\Services\Money\GiroCode;

it('puts recipient, IBAN, amount and reference into the code after the EPC standard', function () {
    $payload = app(GiroCode::class)->payload('Lea Lead', 'DE89370400440532013000', 4250, 'Herbst-Bestellung – Anna Abend');

    expect($payload)->toBe("BCD\n002\n1\nSCT\n\nLea Lead\nDE89370400440532013000\nEUR42.50\n\n\nHerbst-Bestellung – Anna Abend");
});

it('cuts names and references to what banking apps read', function () {
    $lines = explode("\n", app(GiroCode::class)->payload(str_repeat('N', 80), 'DE89370400440532013000', 100, str_repeat('R', 150), 'COBADEFFXXX'));

    expect($lines[4])->toBe('COBADEFFXXX')
        ->and(mb_strlen($lines[5]))->toBe(70)
        ->and(mb_strlen($lines[10]))->toBe(140);
});

it('draws the code as an image the page can show', function () {
    expect(app(GiroCode::class)->dataUri('Lea Lead', 'DE89370400440532013000', 4250, 'Herbst-Bestellung'))
        ->toStartWith('data:image/svg+xml;base64,');
});
