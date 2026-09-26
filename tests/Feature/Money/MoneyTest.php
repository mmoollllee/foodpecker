<?php

use App\Services\Money\Money;

it('rechnet Prozente in Cent ohne Floating-Point-Drift', function () {
    expect(Money::percent(10000, 1.0))->toBe(100);
    expect(Money::percent(12345, 2.5))->toBe(308); // 12345 * 250 / 10000 = 308.625 → 308 (intdiv)
    expect(Money::percent(99, 1.0))->toBe(0); // sehr kleine Beträge
});

it('teilt Cent-Beträge gleichmäßig mit Rest-Verteilung auf', function () {
    expect(Money::splitEvenly(100, 3))->toBe([34, 33, 33]);
    expect(Money::splitEvenly(99, 4))->toBe([25, 25, 25, 24]);
    expect(Money::splitEvenly(0, 5))->toBe([0, 0, 0, 0, 0]);
    expect(Money::splitEvenly(50, 0))->toBe([]);
});

it('rundet aufwärts auf das nächste Vielfache', function () {
    expect(Money::roundUpTo(1234, 100))->toBe(1300);
    expect(Money::roundUpTo(1200, 100))->toBe(1200);
    expect(Money::roundUpTo(1, 1000))->toBe(1000);
    expect(Money::roundUpTo(100, 0))->toBe(100);
});

it('formatiert Beträge im deutschen Stil', function () {
    expect(Money::format(0))->toBe('0,00 €');
    expect(Money::format(1234))->toBe('12,34 €');
    expect(Money::format(123456))->toBe('1.234,56 €');
});

it('parses euro amounts typed in German or English notation', function (string $input, ?int $cents) {
    expect(Money::parse($input))->toBe($cents);
})->with([
    'whole euros' => ['12', 1200],
    'decimal comma' => ['12,5', 1250],
    'two decimals' => ['12,50', 1250],
    'thousands and comma' => ['1.234,56', 123456],
    'decimal point' => ['1234.56', 123456],
    'thousands dot only' => ['1.234', 123400],
    'with currency sign' => ['12,99 €', 1299],
    'blank' => ['  ', null],
    'garbage' => ['zwölf', null],
    'too many decimals' => ['1,234', null],
]);

it('formats cents for input fields', function () {
    expect(Money::toInputString(123456))->toBe('1234,56')
        ->and(Money::toInputString(5))->toBe('0,05')
        ->and(Money::toInputString(0))->toBe('0,00');
});

it('splits cents in proportion without ever giving somebody a negative share', function () {
    expect(Money::splitProportionally(2, [1, 1, 1, 1]))->toBe([1, 1, 0, 0])
        ->and(Money::splitProportionally(390, [3700, 3700, 3700, 3700, 3700, 3700, 3700, 100]))->toBe([56, 56, 56, 56, 55, 55, 55, 1])
        ->and(Money::splitProportionally(100, ['a' => 0, 'b' => 0]))->toBe(['a' => 50, 'b' => 50]);
});
