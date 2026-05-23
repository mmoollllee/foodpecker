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
