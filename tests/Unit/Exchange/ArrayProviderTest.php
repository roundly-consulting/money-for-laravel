<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\ExchangeRateUnavailable;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exchange\Providers\ArrayExchangeRateProvider;
use RoundlyConsulting\Money\Ratio;

it('finds direct, inverse and pivot-triangulated rates', function (): void {
    $provider = new ArrayExchangeRateProvider(['EUR' => ['USD' => '1.0854', 'CZK' => '25'], 'GBP/USD' => '1.25']);

    expect((string) $provider->rate(Currency::of('EUR'), Currency::of('USD'))->rate)->toBe('5427/5000')
        ->and((string) $provider->rate(Currency::of('USD'), Currency::of('EUR'))->rate)->toBe('5000/5427')
        ->and($provider->rate(Currency::of('USD'), Currency::of('CZK'))->rate->equals(Ratio::of(25)->divide(Ratio::of('1.0854'))))->toBeTrue()
        ->and((string) $provider->rate(Currency::of('USD'), Currency::of('GBP'))->rate)->toBe('4/5')
        ->and($provider->rate(Currency::of('EUR'), Currency::of('EUR'))->rate->equals(Ratio::one()))->toBeTrue()
        ->and($provider->rate(Currency::of('EUR'), Currency::of('USD'))->source)->toBe('config');
});

it('is date-agnostic but records the date', function (): void {
    $provider = new ArrayExchangeRateProvider(['EUR/USD' => '1.1']);

    expect($provider->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::parse('2020-01-01'))->date->toDateString())->toBe('2020-01-01');
});

it('reports missing pairs', function (): void {
    $provider = new ArrayExchangeRateProvider(['EUR/USD' => '1.1'], pivot: null);

    expect(fn () => $provider->rate(Currency::of('USD'), Currency::of('CZK')))->toThrow(ExchangeRateUnavailable::class)
        ->and(fn () => (new ArrayExchangeRateProvider(['EUR/USD' => '1.1']))->rate(Currency::of('GBP'), Currency::of('CZK')))->toThrow(ExchangeRateUnavailable::class);
});

it('refuses malformed rate config', function (array $rates): void {
    new ArrayExchangeRateProvider($rates);
})->throws(InvalidMoneyConfiguration::class)->with([
    'no slash' => [['EURUSD' => '1.1']],
    'float' => [['EUR/USD' => 1.1]],
    'zero' => [['EUR' => ['USD' => '0']]],
]);
