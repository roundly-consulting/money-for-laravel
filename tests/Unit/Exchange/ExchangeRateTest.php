<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\InvalidExchangeRate;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Ratio;

function rateOn(string $from, string $to, string $rate, string $date = '2026-09-25', string $source = 'ecb'): ExchangeRate
{
    return ExchangeRate::fromDecimal($from, $to, $rate, CarbonImmutable::parse($date), $source);
}

it('holds an exact ratio', function (): void {
    $rate = rateOn('EUR', 'USD', '1.0854');

    expect((string) $rate->rate)->toBe('5427/5000')
        ->and($rate->from->code)->toBe('EUR')
        ->and($rate->to->code)->toBe('USD')
        ->and($rate->date->toDateString())->toBe('2026-09-25')
        ->and($rate->source)->toBe('ecb')
        ->and($rate->decimal())->toBe('1.0854000000')
        ->and($rate->decimal(2))->toBe('1.09')
        ->and(ExchangeRate::fromDecimal(Currency::of('EUR'), Currency::of('CZK'), '25.1', CarbonImmutable::now())->source)->toBe('manual');
});

it('inverts and crosses exactly', function (): void {
    $eurCzk = rateOn('EUR', 'CZK', '24.345');
    $eurUsd = rateOn('EUR', 'USD', '1.1403', '2026-09-24', 'manual');

    $usdCzk = $eurUsd->invert()->through($eurCzk);

    expect((string) $usdCzk->rate)->toBe((string) Ratio::of('24.345')->divide(Ratio::of('1.1403')))
        ->and($usdCzk->from->code)->toBe('USD')
        ->and($usdCzk->to->code)->toBe('CZK')
        ->and($usdCzk->date->toDateString())->toBe('2026-09-24')
        ->and($usdCzk->source)->toBe('manual+ecb')
        ->and($eurCzk->through(rateOn('CZK', 'GBP', '0.035', '2026-09-26'))->source)->toBe('ecb')
        ->and(fn () => $eurCzk->through($eurUsd))->toThrow(CurrencyMismatch::class);
});

it('converts with one rounding, exponent-aware', function (): void {
    expect(rateOn('EUR', 'USD', '1.0854')->convert(Money::ofMinor(1000, 'EUR'))->minor())->toBe('1085')
        ->and(rateOn('EUR', 'USD', '1.0855')->convert(Money::ofMinor(1000, 'EUR'))->minor())->toBe('1086')
        ->and(rateOn('EUR', 'USD', '1.00005')->convert(Money::ofMinor(10000, 'EUR'))->minor())->toBe('10000')
        ->and(rateOn('EUR', 'USD', '1.00005')->convert(Money::ofMinor(10000, 'EUR'), RoundingMode::HalfAwayFromZero)->minor())->toBe('10001')
        ->and(rateOn('EUR', 'JPY', '179.7')->convert(Money::ofMinor(1000, 'EUR'))->minor())->toBe('1797')
        ->and(rateOn('JPY', 'BHD', '0.0025')->convert(Money::ofMinor(1000, 'JPY'))->minor())->toBe('2500');
});

it('round-trips JPY → BHD → JPY and EUR → ETH → EUR up to the final rounding', function (): void {
    $jpyBhd = rateOn('JPY', 'BHD', '0.00251');
    $bhdJpy = $jpyBhd->invert();

    expect($bhdJpy->convert($jpyBhd->convert(Money::ofMinor(100000, 'JPY')))->minor())->toBe('100000');

    $eth = Currency::custom('ETH', 18);
    $eurEth = new ExchangeRate(Currency::of('EUR'), $eth, Ratio::of('0.000412345678901234'), CarbonImmutable::now(), 'manual');

    $wei = $eurEth->convert(Money::ofMinor(1000, 'EUR'));

    expect($wei->minor())->toBe('4123456789012340')
        ->and($eurEth->invert()->convert($wei)->minor())->toBe('1000');
});

it('refuses a converted amount beyond 65 digits', function (): void {
    rateOn('EUR', 'USD', '1000000')->convert(Money::ofMinor(str_repeat('9', 62), 'EUR'));
})->throws(AmountOverflow::class, 'convert');

it('refuses a money in another currency and invalid rates', function (): void {
    expect(fn () => rateOn('EUR', 'USD', '1.1')->convert(Money::ofMinor(1, 'USD')))->toThrow(CurrencyMismatch::class)
        ->and(fn () => rateOn('EUR', 'USD', '0'))->toThrow(InvalidExchangeRate::class)
        ->and(fn () => rateOn('EUR', 'USD', '-1'))->toThrow(InvalidExchangeRate::class)
        ->and(fn () => rateOn('EUR', 'EUR', '2'))->toThrow(InvalidExchangeRate::class)
        ->and(rateOn('EUR', 'EUR', '1')->rate->equals(Ratio::one()))->toBeTrue();
});
