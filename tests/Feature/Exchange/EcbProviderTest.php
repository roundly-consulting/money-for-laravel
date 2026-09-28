<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Enums\EcbFeed;
use RoundlyConsulting\Money\Exceptions\ExchangeRateFetchFailed;
use RoundlyConsulting\Money\Exceptions\ExchangeRateUnavailable;
use RoundlyConsulting\Money\Exchange\ExchangeManager;
use RoundlyConsulting\Money\Exchange\Providers\EcbExchangeRateProvider;
use RoundlyConsulting\Money\Ratio;

function ecbXml(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../../Fixtures/ecb/'.$name.'.xml');
}

function ecb(): EcbExchangeRateProvider
{
    $source = app(ExchangeManager::class)->source('ecb');

    assert($source instanceof EcbExchangeRateProvider);

    return $source;
}

function fakeEcb(?string $recent = null): void
{
    Http::fake([
        '*eurofxref-daily.xml' => Http::response(ecbXml('daily')),
        '*eurofxref-hist-90d.xml' => Http::response($recent ?? ecbXml('hist-90d-trimmed')),
        '*eurofxref-hist.xml' => Http::response(ecbXml('hist-trimmed')),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-25 17:00', 'Europe/Berlin'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('serves today from the daily feed, EUR-based and crossed exactly', function (): void {
    fakeEcb();

    $provider = ecb();

    expect((string) $provider->rate(Currency::of('EUR'), Currency::of('USD'))->rate)->toBe((string) Ratio::of('1.1403'))
        ->and($provider->rate(Currency::of('USD'), Currency::of('EUR'))->rate->equals(Ratio::of('1.1403')->invert()))->toBeTrue()
        ->and($provider->rate(Currency::of('USD'), Currency::of('CZK'))->rate->equals(Ratio::of('24.345')->divide(Ratio::of('1.1403'))))->toBeTrue()
        ->and($provider->rate(Currency::of('EUR'), Currency::of('EUR'))->rate->equals(Ratio::one()))->toBeTrue()
        ->and($provider->rate(Currency::of('EUR'), Currency::of('USD'))->source)->toBe('ecb')
        ->and($provider->name())->toBe('ecb');

    Http::assertSentCount(1);
});

it('serves older dates from the 90-day feed, weekends from the last published day', function (): void {
    fakeEcb();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-27 12:00', 'Europe/Berlin')); // Sunday

    $saturday = ecb()->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::parse('2026-09-26'));
    $wednesday = ecb()->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::parse('2026-09-23'));

    expect($saturday->date->toDateString())->toBe('2026-09-25')
        ->and($wednesday->date->toDateString())->toBe('2026-09-23');
});

it('reads a requested date as its calendar day, whatever the app timezone', function (): void {
    fakeEcb();
    config(['app.timezone' => 'Europe/Helsinki']);
    date_default_timezone_set('Europe/Helsinki');

    try {
        // Midnight of the 24th in Helsinki is 23:00 on the 23rd in Berlin.
        $thursday = ecb()->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::parse('2026-09-24'));

        expect($thursday->date->toDateString())->toBe('2026-09-24')
            ->and((string) $thursday->rate)->toBe((string) Ratio::of('1.1367'));
    } finally {
        date_default_timezone_set('UTC');
    }
});

it('serves a day that has begun east of the exchange timezone from the daily feed', function (): void {
    fakeEcb();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-26 06:00', 'Asia/Tokyo')); // 23:00 on the 25th in Berlin

    $rate = ecb()->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::now('Asia/Tokyo'));

    expect($rate->date->toDateString())->toBe('2026-09-25')
        ->and((string) $rate->rate)->toBe((string) Ratio::of('1.1403'));

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), 'eurofxref-daily.xml'));
    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), 'eurofxref-hist-90d.xml'));
});

it('refuses future, too old, stale and unknown requests', function (): void {
    fakeEcb();

    $provider = ecb();

    expect(fn () => $provider->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::parse('2026-09-26')))->toThrow(ExchangeRateUnavailable::class, 'future')
        ->and(fn () => $provider->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::parse('2026-01-01')))->toThrow(ExchangeRateUnavailable::class)
        ->and(fn () => $provider->rate(Currency::of('EUR'), Currency::of('KWD')))->toThrow(ExchangeRateUnavailable::class)
        ->and(fn () => $provider->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::parse('2026-09-21')))->toThrow(ExchangeRateUnavailable::class);
});

it('goes stale for a currency that left the feed', function (): void {
    // USD is only quoted on the oldest day of this 90-day file.
    $recent = preg_replace('/\s*<Cube currency="USD" rate="[^"]+"\/>/', '', ecbXml('hist-90d-trimmed'), 3) ?? '';
    fakeEcb($recent);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 17:00', 'Europe/Berlin'));

    expect((string) ecb()->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::parse('2026-09-24'))->rate)->toBe((string) Ratio::of('1.1463'))
        ->and(fn () => ecb()->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::parse('2026-10-02')))
        ->toThrow(ExchangeRateUnavailable::class, 'older than 7 days')
        ->and((string) ecb()->rate(Currency::of('EUR'), Currency::of('CZK'), CarbonImmutable::parse('2026-10-02'))->rate)->toBe((string) Ratio::of('24.345'));
});

it('caches the parsed feed as scalars only', function (): void {
    fakeEcb();

    ecb()->rate(Currency::of('EUR'), Currency::of('USD'));
    ecb()->rate(Currency::of('EUR'), Currency::of('GBP'));

    Http::assertSentCount(1);

    $cached = Cache::get('money:exchange:ecb:feed:daily');

    expect($cached)->toBeArray()
        ->and($cached['2026-09-25']['USD'])->toBe('1.1403');

    array_walk_recursive($cached, fn (mixed $value) => expect(is_object($value))->toBeFalse());
});

it('always downloads on fetch, even over a warm stale cache', function (): void {
    fakeEcb();

    Cache::put('money:exchange:ecb:feed:daily', ['2026-09-24' => ['USD' => '1.0']], 3600);

    $rates = ecb()->fetch(CarbonImmutable::parse('2026-09-25'), CarbonImmutable::parse('2026-09-25'));

    Http::assertSentCount(1);

    expect($rates)->toHaveCount(29)
        ->and($rates[0]->from->code)->toBe('EUR')
        ->and($rates[0]->date->toDateString())->toBe('2026-09-25')
        ->and(Cache::get('money:exchange:ecb:feed:daily'))->toHaveKey('2026-09-25');
});

it('never caches the full-history feed, which no lookup reads', function (): void {
    fakeEcb();

    // The parsed history is megabytes: over memcached's 1 MB item limit the put fails silently.
    $rates = ecb()->usingFeed(EcbFeed::History)->fetch(CarbonImmutable::parse('2026-09-17'), CarbonImmutable::parse('2026-09-25'));

    expect($rates)->not->toBeEmpty()
        ->and(Cache::get('money:exchange:ecb:feed:history'))->toBeNull();

    ecb()->usingFeed(EcbFeed::Recent)->fetch(CarbonImmutable::parse('2026-09-24'), CarbonImmutable::parse('2026-09-25'));

    expect(Cache::get('money:exchange:ecb:feed:recent'))->toBeArray()->toHaveKey('2026-09-24');
});

it('fetches a feed window and counts currencies the registry does not know', function (): void {
    fakeEcb();

    config(['money.currencies.iso' => false, 'money.currencies.custom' => ['USD' => ['exponent' => 2]]]);
    app()->forgetInstance(CurrencyRegistry::class);
    config(['money.currencies.custom' => ['USD' => ['exponent' => 2], 'EUR' => ['exponent' => 2]]]);

    $provider = ecb()->usingFeed(EcbFeed::Recent);
    $rates = $provider->fetch(CarbonImmutable::parse('2026-09-23'), CarbonImmutable::parse('2026-09-24'));

    expect($rates)->toHaveCount(2)
        ->and($provider->skippedUnknownCurrencies())->toBe(56);
});

it('fails loud on HTTP errors', function (): void {
    Http::fake(['*' => Http::response('', 503)]);

    expect(fn () => ecb()->rate(Currency::of('EUR'), Currency::of('USD')))->toThrow(ExchangeRateFetchFailed::class, '503')
        ->and(Cache::get('money:exchange:ecb:feed:daily'))->toBeNull();
});

it('refuses oversize bodies', function (): void {
    config(['money.exchange.providers.ecb.max_bytes' => 100]);
    fakeEcb();

    expect(fn () => ecb()->fetch(CarbonImmutable::now(), CarbonImmutable::now()))->toThrow(ExchangeRateFetchFailed::class, 'bytes');
});

it('refuses a DOCTYPE feed without caching it', function (): void {
    Http::fake(['*' => Http::response(ecbXml('doctype'))]);

    expect(fn () => ecb()->fetch(CarbonImmutable::now(), CarbonImmutable::now()))->toThrow(ExchangeRateFetchFailed::class, 'DOCTYPE')
        ->and(Cache::get('money:exchange:ecb:feed:daily'))->toBeNull();
});

it('retries transient failures', function (): void {
    Http::fake(['*' => Http::sequence()->push('', 500)->push(ecbXml('daily'))]);

    expect((string) ecb()->rate(Currency::of('EUR'), Currency::of('USD'))->rate)->toBe((string) Ratio::of('1.1403'));

    Http::assertSentCount(2);
});

it('reports an unreachable feed as a fetch failure, so a chain falls through', function (): void {
    Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);
    config([
        'money.exchange.cache.enabled' => false,
        'money.exchange.providers.ecb.retries' => 0,
        'money.exchange.chain' => ['ecb', 'config'],
        'money.exchange.providers.config.rates' => ['EUR' => ['USD' => '1.1']],
    ]);

    expect(fn () => ecb()->rate(Currency::of('EUR'), Currency::of('USD')))->toThrow(ExchangeRateFetchFailed::class, 'timed out')
        ->and(fn () => ecb()->fetch(CarbonImmutable::now(), CarbonImmutable::now()))->toThrow(ExchangeRateFetchFailed::class, 'timed out')
        ->and((string) app(ExchangeManager::class)->provider('chain')->rate(Currency::of('EUR'), Currency::of('USD'))->rate)->toBe('11/10');
});
