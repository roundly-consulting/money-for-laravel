<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\InvalidExchangeRate;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Ratio;
use RoundlyConsulting\Money\Support\StorableRate;

function storableRate(Ratio $rate): ExchangeRate
{
    return new ExchangeRate(Currency::of('EUR'), Currency::of('USD'), $rate, CarbonImmutable::parse('2026-09-24'), 'manual');
}

it('returns the exact decimal of a rate of up to 40 characters', function (): void {
    $forty = '1234567890123456789.12345678901234567891';

    expect(strlen($forty))->toBe(40)
        ->and(StorableRate::decimal(storableRate(Ratio::of('1.1403'))))->toBe('1.1403')
        ->and(StorableRate::decimal(storableRate(Ratio::of($forty))))->toBe($forty);
});

it('refuses a rate with no exact decimal of at most 40 characters', function (Ratio $rate): void {
    StorableRate::decimal(storableRate($rate));
})->throws(InvalidExchangeRate::class, 'exact decimal')->with([
    'repeating' => [Ratio::of(1, 3)],
    '41 characters' => [Ratio::of('12345678901234567890.12345678901234567891')],
]);
