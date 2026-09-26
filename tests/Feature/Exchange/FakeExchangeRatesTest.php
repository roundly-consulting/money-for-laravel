<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Money\Contracts\CurrencyConverter;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Facades\Exchange;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\MoneyBag;

it('is seen by Money::convertTo even after a real converter was resolved', function (): void {
    app(CurrencyConverter::class);

    $fake = Exchange::fake(['EUR/USD' => '1.0854']);

    expect(Money::ofMinor(1000, 'EUR')->convertTo('USD')->minor())->toBe('1085')
        ->and(Exchange::convert(Money::ofMinor(1000, 'USD'), 'EUR')->minor())->toBe('921')
        ->and(Exchange::convertWithRate(Money::ofMinor(1000, 'EUR'), 'USD')->rate->source)->toBe('fake')
        ->and(Exchange::driver())->toBe($fake)
        ->and(Exchange::provider('ecb'))->toBe($fake);

    $fake->assertRateRequested('eur', 'usd');
});

it('asserts what was and was not requested', function (): void {
    $fake = Exchange::fake(['EUR/USD' => '1.1']);

    $fake->assertNothingRequested();

    expect(fn () => $fake->assertRateRequested('EUR', 'USD'))->toThrow(AssertionFailedError::class);

    $fake->rate(Currency::of('EUR'), Currency::of('USD'));

    expect(fn () => $fake->assertNothingRequested())->toThrow(AssertionFailedError::class);
});

it('totals a money bag with one rounding over the exact sum', function (): void {
    // Each leg alone is x.5 minor units: rounding per currency would give 1 + 1 + 1 = 3 extra
    // units under HalfAwayFromZero; the exact sum is 1.5 → 2 under HalfEven.
    Exchange::fake(['USD/EUR' => '0.5', 'GBP/EUR' => '0.5', 'CHF/EUR' => '0.5']);

    $bag = MoneyBag::of(Money::ofMinor(1, 'USD'), Money::ofMinor(1, 'GBP'), Money::ofMinor(1, 'CHF'), Money::ofMinor(100, 'EUR'));

    expect($bag->total('EUR')->minor())->toBe('102')
        ->and($bag->total('EUR', rounding: RoundingMode::HalfAwayFromZero)->minor())->toBe('102')
        ->and(MoneyBag::of(Money::ofMinor(1, 'USD'))->total('EUR')->minor())->toBe('0')
        ->and(MoneyBag::of()->total('EUR')->isZero())->toBeTrue()
        ->and(MoneyBag::of(Money::ofMinor(1, 'USD'), Money::ofMinor(1, 'GBP'), Money::ofMinor(1, 'CHF'))->total(Currency::of('EUR'))->minor())->toBe('2');
});
