<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Currencies\IsoCurrencyData;
use RoundlyConsulting\Money\Currency;

it('bundles the pinned ISO 4217 snapshot', function (): void {
    $currencies = IsoCurrencyData::load();

    // SIX List One published 2026-09-17, minus every "N.A." minor-unit row.
    expect($currencies)->toHaveCount(165);

    $codes = array_map(static fn (Currency $currency): string => $currency->code, $currencies);
    $numerics = array_map(static fn (Currency $currency): ?int => $currency->numericCode, $currencies);

    expect(array_unique($codes))->toHaveCount(165)
        ->and(array_unique($numerics))->toHaveCount(165);

    foreach ($currencies as $currency) {
        expect($currency->iso)->toBeTrue()
            ->and($currency->exponent)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(4);
    }
});

it('pins spot values', function (string $code, string $numeric, int $exponent): void {
    $currency = Currency::of($code);

    expect($currency->numericCodeString())->toBe($numeric)
        ->and($currency->exponent)->toBe($exponent);
})->with([
    ['EUR', '978', 2], ['USD', '840', 2], ['JPY', '392', 0], ['BHD', '048', 3], ['KWD', '414', 3],
    ['ISK', '352', 0], ['HUF', '348', 2], ['CZK', '203', 2], ['CLF', '990', 4], ['UYW', '927', 4],
]);

it('omits withdrawn and minor-unit-less codes', function (string $code): void {
    $codes = array_map(static fn (Currency $currency): string => $currency->code, IsoCurrencyData::load());

    expect($codes)->not->toContain($code);
})->with(['HRK', 'XAU', 'XDR', 'XXX', 'XAG', 'XTS']);
