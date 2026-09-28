<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Money\Actions\StoreExchangeRatesAction;
use RoundlyConsulting\Money\Exceptions\ExchangeRateUnavailable;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Exchange\RateCacheGeneration;
use RoundlyConsulting\Money\Facades\Exchange;
use RoundlyConsulting\Money\Models\CurrencyRate;
use RoundlyConsulting\Money\Money;

function cachedCzk(?CarbonImmutable $on = null): string
{
    return (string) Money::ofMajor('100', 'EUR')->convertTo('CZK', $on);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-25 16:30', 'Europe/Berlin'));

    config(['money.exchange.default' => 'database', 'money.exchange.cache.enabled' => true]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('serves a corrected manual rate at once, to every call style', function (): void {
    Exchange::rates()->manual('EUR', 'CZK', '25.10');

    expect(cachedCzk())->toBe('2510.00 CZK');

    Exchange::rates()->manual('EUR', 'CZK', '24.00');

    expect(cachedCzk())->toBe('2400.00 CZK')
        ->and(cachedCzk(CarbonImmutable::now()))->toBe('2400.00 CZK');
});

it('forgets cached lookups after store(), a refresh and a prune', function (): void {
    Exchange::rates()->store(ExchangeRate::fromDecimal('EUR', 'CZK', '25', CarbonImmutable::parse('2026-09-24'), 'ecb'));

    expect(cachedCzk())->toBe('2500.00 CZK')
        ->and(cachedCzk(CarbonImmutable::parse('2026-09-24')))->toBe('2500.00 CZK');

    // A refresh writes through the same action: the daily feed quotes 24.345 for the 25th.
    Http::fake(['*eurofxref-daily.xml' => Http::response((string) file_get_contents(__DIR__.'/../../Fixtures/ecb/daily.xml'))]);
    Exchange::rates()->refresh();

    expect(cachedCzk())->toBe('2434.50 CZK');

    // A pruned row a cached historical lookup used is not served any more.
    Exchange::rates()->prune(CarbonImmutable::parse('2026-09-25'));

    expect(fn () => cachedCzk(CarbonImmutable::parse('2026-09-24')))->toThrow(ExchangeRateUnavailable::class);
});

it('keeps the cache when nothing was written', function (): void {
    Exchange::rates()->manual('EUR', 'CZK', '25');
    cachedCzk();

    CurrencyRate::query()->pair('EUR', 'CZK')->update(['rate' => '30']); // behind the package's back

    Exchange::rates()->store();
    Exchange::rates()->prune(CarbonImmutable::parse('2026-01-01'));
    Exchange::rates()->prune(CarbonImmutable::parse('2026-12-01'), includeManual: true, pretend: true);

    expect(cachedCzk())->toBe('2500.00 CZK');
});

it('invalidates again once a surrounding transaction commits', function (): void {
    Exchange::rates()->manual('EUR', 'CZK', '25');
    cachedCzk();

    DB::transaction(function (): void {
        app(StoreExchangeRatesAction::class)->execute([ExchangeRate::fromDecimal('EUR', 'CZK', '26', CarbonImmutable::now(), 'manual')]);

        // Inside the transaction the new row is visible and the cache already missed.
        expect(cachedCzk())->toBe('2600.00 CZK');

        // Meanwhile another process still reads the committed (old) row and caches it
        // under the current generation — simulate that interleaving.
        $generation = (new RateCacheGeneration(Cache::store('array'), 'money:exchange'))->current();

        Cache::store('array')->put(
            "money:exchange:{$generation}:database:EUR:CZK:latest",
            ['num' => '25', 'den' => '1', 'date' => '2026-09-25', 'tz' => 'Europe/Berlin', 'source' => 'manual'],
            3600,
        );

        expect(cachedCzk())->toBe('2500.00 CZK');
    });

    expect(cachedCzk())->toBe('2600.00 CZK');
});

it('does not touch the cache store when caching is off', function (): void {
    config(['money.exchange.cache.enabled' => false, 'money.exchange.cache.store' => 'missing-store']);

    Exchange::rates()->manual('EUR', 'CZK', '25');
    Exchange::rates()->prune(CarbonImmutable::parse('2026-12-01'), includeManual: true);

    expect(CurrencyRate::query()->count())->toBe(0);
});

it('starts a generation once and replaces it on every bump', function (): void {
    $generation = new RateCacheGeneration(Cache::store('array'), 'money:exchange');

    $first = $generation->current();

    expect($generation->current())->toBe($first)
        ->and(Cache::store('array')->get('money:exchange:generation'))->toBe($first);

    $generation->bump();

    expect($generation->current())->not->toBe($first)
        ->and($generation->current())->toMatch('/\A[0-9A-Za-z]{16}\z/');
});
