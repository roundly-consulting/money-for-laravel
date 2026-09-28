<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\ExchangeRateUnavailable;
use RoundlyConsulting\Money\Exchange\Providers\DatabaseExchangeRateProvider;
use RoundlyConsulting\Money\Models\CurrencyRate;
use RoundlyConsulting\Money\Ratio;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-28 10:00', 'Europe/Berlin')); // Monday

    CurrencyRate::factory()->pair('EUR', 'USD', '1.10')->on('2026-09-24')->create();
    CurrencyRate::factory()->pair('EUR', 'USD', '1.20')->on('2026-09-25')->create(); // Friday
    CurrencyRate::factory()->pair('EUR', 'CZK', '25')->on('2026-09-25')->from('ecb')->create();
    CurrencyRate::factory()->pair('EUR', 'GBP', '0.8')->on('2026-09-10')->create();

    $this->provider = new DatabaseExchangeRateProvider(maxAgeDays: 7);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('takes the newest rate on or before the date', function (): void {
    $eur = Currency::of('EUR');
    $usd = Currency::of('USD');

    expect((string) $this->provider->rate($eur, $usd)->rate)->toBe('6/5')
        ->and($this->provider->rate($eur, $usd)->date->toDateString())->toBe('2026-09-25')
        ->and((string) $this->provider->rate($eur, $usd, CarbonImmutable::parse('2026-09-24'))->rate)->toBe('11/10')
        ->and((string) $this->provider->rate($eur, $usd, CarbonImmutable::parse('2026-09-27'))->rate)->toBe('6/5');
});

it('dates rates in the exchange timezone, like the ecb driver', function (): void {
    $rate = $this->provider->rate(Currency::of('EUR'), Currency::of('USD'));

    expect($rate->date->timezoneName)->toBe('Europe/Berlin')
        ->and($rate->date->equalTo(CarbonImmutable::parse('2026-09-25', 'Europe/Berlin')))->toBeTrue()
        ->and($this->provider->rate(Currency::of('USD'), Currency::of('CZK'))->date->timezoneName)->toBe('Europe/Berlin');
});

it('reads the requested date as its own calendar day, never shifted into the exchange timezone', function (): void {
    // 23:30 UTC on the 24th is already the 25th in Berlin — but the caller asked for the 24th.
    expect((string) $this->provider->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::parse('2026-09-24 23:30', 'UTC'))->rate)->toBe('11/10');
});

it('reads a date-only input as that calendar day, whatever the app timezone', function (string $appTimezone): void {
    config(['app.timezone' => $appTimezone]);
    date_default_timezone_set($appTimezone);

    try {
        // Midnight of the 25th in Helsinki or Tokyo is still the 24th in Berlin.
        $friday = $this->provider->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::parse('2026-09-25'));

        expect((string) $friday->rate)->toBe('6/5')
            ->and($friday->date->toDateString())->toBe('2026-09-25')
            ->and((string) $this->provider->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::parse('2026-09-24'))->rate)->toBe('11/10');
    } finally {
        date_default_timezone_set('UTC');
    }
})->with(['Europe/Helsinki', 'Asia/Tokyo', 'Pacific/Auckland', 'America/Los_Angeles']);

it('serves a day that has begun where the caller is, even before it begins in the exchange timezone', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-29 06:00', 'Asia/Tokyo')); // 23:00 on the 28th in Berlin

    expect($this->provider->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::now('Asia/Tokyo'))->date->toDateString())->toBe('2026-09-25')
        ->and(fn () => $this->provider->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::parse('2026-09-30', 'Asia/Tokyo')))->toThrow(ExchangeRateUnavailable::class, 'future');
});

it('inverts and triangulates through the pivot', function (): void {
    $viaPivot = $this->provider->rate(Currency::of('USD'), Currency::of('CZK'));

    expect((string) $this->provider->rate(Currency::of('USD'), Currency::of('EUR'))->rate)->toBe('5/6')
        ->and($viaPivot->rate->equals(Ratio::of(25)->divide(Ratio::of('1.2'))))->toBeTrue()
        ->and($viaPivot->source)->toBe('manual+ecb')
        ->and($this->provider->rate(Currency::of('EUR'), Currency::of('EUR'))->rate->equals(Ratio::one()))->toBeTrue();
});

it('refuses stale, missing and future rates', function (): void {
    expect(fn () => $this->provider->rate(Currency::of('EUR'), Currency::of('GBP')))->toThrow(ExchangeRateUnavailable::class, 'older than 7 days')
        ->and(fn () => $this->provider->rate(Currency::of('EUR'), Currency::of('JPY')))->toThrow(ExchangeRateUnavailable::class)
        ->and(fn () => $this->provider->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::parse('2026-09-23')))->toThrow(ExchangeRateUnavailable::class)
        ->and(fn () => $this->provider->rate(Currency::of('EUR'), Currency::of('USD'), CarbonImmutable::parse('2026-09-29')))->toThrow(ExchangeRateUnavailable::class, 'future');
});

it('stores dates as Y-m-d so string comparison works on every engine', function (): void {
    $raw = CurrencyRate::query()->where('quote_currency', 'CZK')->toBase()->value('effective_date');

    expect(substr((string) $raw, 0, 10))->toBe('2026-09-25')
        ->and(CurrencyRate::query()->effectiveOnOrBefore(CarbonImmutable::parse('2026-09-25'))->pair('EUR', 'CZK')->count())->toBe(1);
});

it('measures staleness in calendar days, whatever the app and exchange timezones', function (string $appTimezone, string $exchangeTimezone): void {
    config(['app.timezone' => $appTimezone]);
    date_default_timezone_set($appTimezone);

    try {
        // Monday the 28th everywhere: the Monday-before-last row is exactly max_age_days old.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-28 12:00', 'UTC'));
        CurrencyRate::factory()->pair('EUR', 'CHF', '0.93')->on('2026-09-21')->create();
        CurrencyRate::factory()->pair('EUR', 'SEK', '11.2')->on('2026-09-20')->create();

        $provider = new DatabaseExchangeRateProvider(maxAgeDays: 7, timezone: $exchangeTimezone);

        expect($provider->rate(Currency::of('EUR'), Currency::of('CHF'))->date->toDateString())->toBe('2026-09-21')
            ->and(fn () => $provider->rate(Currency::of('EUR'), Currency::of('SEK')))->toThrow(ExchangeRateUnavailable::class, 'older than 7 days');
    } finally {
        date_default_timezone_set('UTC');
    }
})->with([
    'app UTC, exchange Berlin' => ['UTC', 'Europe/Berlin'],
    'app UTC, exchange New York' => ['UTC', 'America/New_York'],
    'app Tokyo, exchange Berlin' => ['Asia/Tokyo', 'Europe/Berlin'],
]);
