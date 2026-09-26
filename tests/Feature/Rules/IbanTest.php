<?php

use App\Rules\Iban;

it('accepts IBANs whose check digits add up, also with spaces and in lower case', function (string $iban) {
    expect(Iban::isValid($iban))->toBeTrue();
})->with([
    'German' => 'DE89370400440532013000',
    'Austrian' => 'AT611904300234573201',
    'typed with spaces in lower case' => 'de89 3704 0044 0532 0130 00',
    'copied with non-breaking spaces' => "DE89\u{00A0}3704\u{00A0}0044\u{00A0}0532\u{00A0}0130\u{00A0}00",
]);

it('rejects IBANs with wrong check digits or in the wrong shape', function (string $iban) {
    expect(Iban::isValid($iban))->toBeFalse();
})->with([
    'a typo' => 'DE89370400440532013001',
    'too short' => 'DE8937040044',
    'no country code' => '89370400440532013000',
    'empty' => '',
]);

it('stores IBANs without spaces in upper case', function () {
    expect(Iban::normalize(' de89 3704 0044 0532 0130 00 '))->toBe('DE89370400440532013000')
        ->and(Iban::normalize('   '))->toBeNull();
});
