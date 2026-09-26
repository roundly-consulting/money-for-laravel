<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\MoneyBag;

it('accumulates per currency, sorted by code', function (): void {
    $bag = MoneyBag::of(Money::ofMinor(100, 'USD'), Money::ofMinor(50, 'EUR'), Money::ofMinor(25, 'EUR'));

    expect($bag->get('EUR')->minor())->toBe('75')
        ->and($bag->get('usd')->minor())->toBe('100')
        ->and($bag->get('JPY')->equals(Money::zero('JPY')))->toBeTrue()
        ->and(array_map(static fn (Currency $currency): string => $currency->code, $bag->currencies()))->toBe(['EUR', 'USD'])
        ->and(array_map(static fn (Money $money): string => (string) $money, $bag->all()))->toBe(['0.75 EUR', '1.00 USD'])
        ->and($bag->isEmpty())->toBeFalse();
});

it('is immutable', function (): void {
    $bag = MoneyBag::of(Money::ofMinor(1, 'EUR'));
    $more = $bag->add(Money::ofMinor(1, 'EUR'));

    expect($bag->get('EUR')->minor())->toBe('1')
        ->and($more->get('EUR')->minor())->toBe('2');
});

it('drops currencies that return to zero', function (): void {
    $bag = MoneyBag::of(Money::ofMinor(100, 'EUR'), Money::ofMinor(5, 'USD'))
        ->subtract(Money::ofMinor(100, 'EUR'));

    expect($bag->currencies())->toHaveCount(1)
        ->and($bag->get('EUR')->isZero())->toBeTrue()
        ->and(MoneyBag::of()->isEmpty())->toBeTrue()
        ->and($bag->subtract(Money::ofMinor(5, 'USD'))->isEmpty())->toBeTrue();
});

it('refuses a same-code currency with another exponent', function (): void {
    MoneyBag::of(Money::ofMinor(1, 'EUR'))->add(Money::ofMinor(1, new Currency('EUR', 3, 'Euro')));
})->throws(CurrencyMismatch::class);

it('returns zero for a same-code currency with another exponent', function (): void {
    $other = new Currency('EUR', 3, 'Euro');

    expect(MoneyBag::of(Money::ofMinor(1, 'EUR'))->get($other)->equals(Money::zero($other)))->toBeTrue();
});
