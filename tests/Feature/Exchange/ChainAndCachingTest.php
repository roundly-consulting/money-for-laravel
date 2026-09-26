<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Money\Contracts\ExchangeRateProvider;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\ExchangeRateFetchFailed;
use RoundlyConsulting\Money\Exceptions\ExchangeRateUnavailable;
use RoundlyConsulting\Money\Exchange\ExchangeManager;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Exchange\Providers\ArrayExchangeRateProvider;
use RoundlyConsulting\Money\Exchange\Providers\CachingExchangeRateProvider;
use RoundlyConsulting\Money\Exchange\Providers\ChainExchangeRateProvider;

function throwing(Throwable $exception): ExchangeRateProvider
{
    return new class($exception) implements ExchangeRateProvider
    {
        public int $calls = 0;

        public function __construct(private readonly Throwable $exception) {}

        public function rate(Currency $from, Currency $to, ?CarbonInterface $on = null): ExchangeRate
        {
            $this->calls++;

            throw $this->exception;
        }
    };
}

it('falls through unavailable and failed legs to the first success', function (): void {
    $chain = new ChainExchangeRateProvider([
        'database' => throwing(ExchangeRateUnavailable::between(Currency::of('EUR'), Currency::of('USD'))),
        'ecb' => throwing(ExchangeRateFetchFailed::http('x', 500)),
        'config' => new ArrayExchangeRateProvider(['EUR/USD' => '1.1']),
    ]);

    expect((string) $chain->rate(Currency::of('EUR'), Currency::of('USD'))->rate)->toBe('11/10');
});

it('aggregates every reason when all legs fail', function (): void {
    $chain = new ChainExchangeRateProvider([
        'database' => throwing(ExchangeRateUnavailable::between(Currency::of('EUR'), Currency::of('USD'))),
        'ecb' => throwing(ExchangeRateFetchFailed::http('x', 500)),
    ]);

    $chain->rate(Currency::of('EUR'), Currency::of('USD'));
})->throws(ExchangeRateUnavailable::class, 'database: No exchange rate');

it('propagates misconfiguration such as a missing table', function (): void {
    Schema::drop('money_exchange_rates');
    config(['money.exchange.cache.enabled' => false]);

    app(ExchangeManager::class)->provider('chain')->rate(Currency::of('EUR'), Currency::of('USD'));
})->throws(QueryException::class);

it('caches successful lookups as scalar arrays, never failures', function (): void {
    $inner = new class implements ExchangeRateProvider
    {
        public int $calls = 0;

        public function rate(Currency $from, Currency $to, ?CarbonInterface $on = null): ExchangeRate
        {
            $this->calls++;

            if ($to->code === 'JPY') {
                throw ExchangeRateUnavailable::between($from, $to, $on);
            }

            return ExchangeRate::fromDecimal($from, $to, '1.0854', CarbonImmutable::parse('2026-09-25'), 'ecb');
        }
    };

    $cached = new CachingExchangeRateProvider($inner, 'ecb', Cache::store('array'), 60, 'money:exchange', 'Europe/Berlin');

    $first = $cached->rate(Currency::of('EUR'), Currency::of('USD'));
    $second = $cached->rate(Currency::of('EUR'), Currency::of('USD'));

    expect($inner->calls)->toBe(1)
        ->and((string) $second->rate)->toBe((string) $first->rate)
        ->and($second->date->toDateString())->toBe('2026-09-25')
        ->and($second->source)->toBe('ecb')
        ->and(Cache::store('array')->get('money:exchange:ecb:EUR:USD:latest'))->toBe(['num' => '5427', 'den' => '5000', 'date' => '2026-09-25', 'source' => 'ecb'])
        ->and($cached->inner())->toBe($inner);

    $cached->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::parse('2026-09-24 23:30', 'UTC'));
    expect(Cache::store('array')->has('money:exchange:ecb:EUR:USD:2026-09-25'))->toBeTrue();

    expect(fn () => $cached->rate(Currency::of('EUR'), Currency::of('JPY')))->toThrow(ExchangeRateUnavailable::class)
        ->and(fn () => $cached->rate(Currency::of('EUR'), Currency::of('JPY')))->toThrow(ExchangeRateUnavailable::class)
        ->and($inner->calls)->toBe(4);
});

it('ignores a malformed cache entry', function (): void {
    Cache::store('array')->put('money:exchange:config:EUR:USD:latest', ['num' => 5], 60);

    $cached = new CachingExchangeRateProvider(new ArrayExchangeRateProvider(['EUR/USD' => '2']), 'config', Cache::store('array'), 60, 'money:exchange', 'UTC');

    expect((string) $cached->rate(Currency::of('EUR'), Currency::of('USD'))->rate)->toBe('2/1');
});
