<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Money\Contracts\CurrencyConverter;
use RoundlyConsulting\Money\Contracts\ExchangeRateProvider;
use RoundlyConsulting\Money\Contracts\ExchangeRateSource;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exchange\Conversion;
use RoundlyConsulting\Money\Exchange\Converter;
use RoundlyConsulting\Money\Exchange\ExchangeManager;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Exchange\Providers\ArrayExchangeRateProvider;
use RoundlyConsulting\Money\Exchange\Providers\CachingExchangeRateProvider;
use RoundlyConsulting\Money\Exchange\Providers\ChainExchangeRateProvider;
use RoundlyConsulting\Money\Exchange\Providers\DatabaseExchangeRateProvider;
use RoundlyConsulting\Money\Exchange\Providers\EcbExchangeRateProvider;
use RoundlyConsulting\Money\Facades\Exchange;
use RoundlyConsulting\Money\Money;

it('defaults to the ecb driver, cached, and works with no rates table', function (): void {
    Http::fake(['*' => Http::response((string) file_get_contents(__DIR__.'/../../Fixtures/ecb/daily.xml'))]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-25 18:00', 'Europe/Berlin'));
    Schema::drop('money_exchange_rates');

    $manager = app(ExchangeManager::class);

    expect($manager->getDefaultDriver())->toBe('ecb')
        ->and($manager->provider())->toBeInstanceOf(CachingExchangeRateProvider::class)
        ->and(Money::ofMinor(1000, 'EUR')->convertTo('USD')->minor())->toBe('1140')
        ->and(app(ExchangeRateProvider::class))->toBe($manager);

    CarbonImmutable::setTestNow();
});

it('builds each driver with the right wrapping', function (): void {
    $manager = app(ExchangeManager::class);

    expect($manager->provider('config'))->toBeInstanceOf(ArrayExchangeRateProvider::class)
        ->and($manager->provider('chain'))->toBeInstanceOf(ChainExchangeRateProvider::class)
        ->and($manager->provider('database'))->toBeInstanceOf(CachingExchangeRateProvider::class);

    config(['money.exchange.cache.enabled' => false]);
    $fresh = new ExchangeManager(app());

    expect($fresh->provider('database'))->toBeInstanceOf(DatabaseExchangeRateProvider::class);
});

it('returns raw sources for the refresh pipeline', function (): void {
    $manager = app(ExchangeManager::class);

    expect($manager->source('ecb'))->toBeInstanceOf(EcbExchangeRateProvider::class)
        ->and($manager->driver('ecb'))->toBeInstanceOf(CachingExchangeRateProvider::class)
        ->and(fn () => $manager->source('database'))->toThrow(InvalidMoneyConfiguration::class, 'ExchangeRateSource')
        ->and(fn () => $manager->source('nope'))->toThrow(InvalidMoneyConfiguration::class);
});

it('accepts host drivers, cached and fetchable', function (): void {
    $source = new class implements ExchangeRateProvider, ExchangeRateSource
    {
        public function name(): string
        {
            return 'fixer';
        }

        public function fetch(CarbonInterface $from, CarbonInterface $to): iterable
        {
            return [];
        }

        public function rate(Currency $from, Currency $to, ?CarbonInterface $on = null): ExchangeRate
        {
            return ExchangeRate::fromDecimal($from, $to, '2', CarbonImmutable::now(), 'fixer');
        }
    };

    Exchange::extend('fixer', fn () => $source);

    expect(Exchange::driver('fixer'))->toBeInstanceOf(CachingExchangeRateProvider::class)
        ->and(Exchange::source('fixer'))->toBe($source)
        ->and((string) Exchange::driver('fixer')->rate(Currency::of('EUR'), Currency::of('USD'))->rate)->toBe('2/1');
});

it('converts through the configured driver with the configured rounding', function (): void {
    config([
        'money.exchange.default' => 'config',
        'money.exchange.providers.config.rates' => ['EUR' => ['USD' => '1.00005']],
    ]);

    $conversion = Exchange::convertWithRate(Money::ofMinor(10000, 'EUR'), 'USD');

    expect($conversion)->toBeInstanceOf(Conversion::class)
        ->and($conversion->converted->minor())->toBe('10000')
        ->and($conversion->original->minor())->toBe('10000')
        ->and($conversion->rate->source)->toBe('config')
        ->and(Exchange::convert(Money::ofMinor(10000, 'EUR'), 'USD', rounding: RoundingMode::HalfAwayFromZero)->minor())->toBe('10001')
        ->and((string) Exchange::rate(Currency::of('EUR'), Currency::of('USD'))->rate)->toBe('20001/20000');

    config(['money.exchange.rounding' => 'half_away_from_zero']);

    expect(Money::ofMinor(10000, 'EUR')->convertTo('USD')->minor())->toBe('10001');
});

it('returns the input for a same-currency conversion without asking a provider', function (): void {
    $fake = Exchange::fake();
    $money = Money::ofMinor(5, 'EUR');

    expect(Money::ofMinor(5, 'EUR')->convertTo('eur')->equals($money))->toBeTrue()
        ->and(app(CurrencyConverter::class)->convertWithRate($money, Currency::of('EUR'))->rate->source)->toBe('identity');

    $fake->assertNothingRequested();
});

it('binds a fresh converter each time', function (): void {
    expect(app(CurrencyConverter::class))->toBeInstanceOf(Converter::class)
        ->and(app(CurrencyConverter::class))->not->toBe(app(CurrencyConverter::class));
});

it('refuses a provider rate for another currency', function (): void {
    $wrong = new class implements ExchangeRateProvider
    {
        public function rate(Currency $from, Currency $to, ?CarbonInterface $on = null): ExchangeRate
        {
            return ExchangeRate::fromDecimal('GBP', $to, '2', CarbonImmutable::now());
        }
    };

    (new Converter($wrong))->convert(Money::ofMinor(1, 'EUR'), 'USD');
})->throws(CurrencyMismatch::class);
