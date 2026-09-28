<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\DataTransferObjects\RefreshResult;
use RoundlyConsulting\Money\Enums\EcbFeed;
use RoundlyConsulting\Money\Events\ExchangeRatesRefreshed;
use RoundlyConsulting\Money\Exchange\ExchangeManager;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Exchange\RateStore;
use RoundlyConsulting\Money\Facades\Exchange;
use RoundlyConsulting\Money\Jobs\RefreshExchangeRates;
use RoundlyConsulting\Money\Models\CurrencyRate;
use RoundlyConsulting\Money\Ratio;

function ratesAccessorFeed(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../../Fixtures/ecb/'.$name.'.xml');
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-25 16:30', 'Europe/Berlin'));

    Http::fake([
        '*eurofxref-daily.xml' => Http::response(ratesAccessorFeed('daily')),
        '*eurofxref-hist-90d.xml' => Http::response(ratesAccessorFeed('hist-90d-trimmed')),
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('refreshes a source into the table through the facade', function (): void {
    Event::fake([ExchangeRatesRefreshed::class]);

    $result = Exchange::rates()->refresh();

    expect(Exchange::rates())->toBeInstanceOf(RateStore::class)
        ->and($result)->toBeInstanceOf(RefreshResult::class)
        ->and($result->source)->toBe('ecb')
        ->and($result->stored)->toBe(29)
        ->and(CurrencyRate::query()->pair('EUR', 'USD')->value('rate'))->toBe('1.1403');

    Event::assertDispatched(ExchangeRatesRefreshed::class);
});

it('refreshes a window of a named feed', function (): void {
    $result = Exchange::rates()->refresh(
        source: 'ecb',
        feed: EcbFeed::Recent,
        from: CarbonImmutable::parse('2026-09-24'),
        to: now()->parse('2026-09-25'),
    );

    expect($result->stored)->toBe(29 * 2)
        ->and($result->latestDate?->toDateString())->toBe('2026-09-25');
});

it('queues a unique refresh', function (): void {
    Queue::fake();

    Exchange::rates()->refreshLater('ecb', EcbFeed::Recent, CarbonImmutable::parse('2026-09-20'), CarbonImmutable::parse('2026-09-25'));
    Exchange::rates()->refreshLater();

    Queue::assertPushed(RefreshExchangeRates::class, fn (RefreshExchangeRates $job): bool => $job->uniqueId() === 'ecb:recent'
        && $job->from === '2026-09-20'
        && $job->to === '2026-09-25');
    Queue::assertPushed(RefreshExchangeRates::class, fn (RefreshExchangeRates $job): bool => $job->uniqueId() === 'ecb:daily'
        && $job->from === null
        && $job->to === null);

    expect(CurrencyRate::query()->count())->toBe(0);
});

it('stores rates and keeps manual rows through the facade', function (): void {
    $date = CarbonImmutable::parse('2026-09-25');

    $manual = Exchange::rates()->manual('eur', 'usd', '1.20', $date);
    $stored = Exchange::rates()->store(
        ExchangeRate::fromDecimal('EUR', 'USD', '1.1403', $date, 'ecb'),
        ExchangeRate::fromDecimal('EUR', 'CZK', '24.345', $date, 'ecb'),
    );

    expect($manual->source)->toBe('manual')
        ->and($manual->stored)->toBe(1)
        ->and($stored->stored)->toBe(1)
        ->and($stored->skippedManual)->toBe(1)
        ->and(CurrencyRate::query()->pair('EUR', 'USD')->value('rate'))->toBe('1.2')
        ->and(CurrencyRate::query()->pair('EUR', 'CZK')->value('rate'))->toBe('24.345')
        ->and(Exchange::rates()->store()->stored)->toBe(0);
});

it('dates a manual rate today in the exchange timezone unless told otherwise', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-25 23:30', 'UTC')); // already the 26th in Berlin

    $result = Exchange::rates()->manual(Currency::of('EUR'), Currency::of('CZK'), Ratio::of(251, 10));

    $row = CurrencyRate::query()->pair('EUR', 'CZK')->firstOrFail();

    expect($result->latestDate?->toDateString())->toBe('2026-09-26')
        ->and($row->effective_date->toDateString())->toBe('2026-09-26')
        ->and($row->rate)->toBe('25.1')
        ->and($row->source)->toBe('manual');
});

it('prunes, pretends and keeps manual rows unless included', function (): void {
    CurrencyRate::factory()->pair('EUR', 'USD', '1.1')->on('2026-09-01')->from('ecb')->create();
    CurrencyRate::factory()->pair('EUR', 'USD', '1.2')->on('2026-09-02')->from('manual')->create();
    CurrencyRate::factory()->pair('EUR', 'USD', '1.3')->on('2026-09-24')->from('ecb')->create();

    $before = CarbonImmutable::parse('2026-09-10');

    expect(Exchange::rates()->prune($before, pretend: true))->toBe(1)
        ->and(CurrencyRate::query()->count())->toBe(3)
        ->and(Exchange::rates()->prune(before: $before))->toBe(1)
        ->and(Exchange::rates()->prune(before: $before, includeManual: true))->toBe(1)
        ->and(CurrencyRate::query()->pluck('rate')->all())->toBe(['1.3']);
});

it('offers the same API to an injected manager', function (): void {
    $manager = app(ExchangeManager::class);

    $result = $manager->rates()->manual('EUR', 'GBP', '0.85', CarbonImmutable::parse('2026-09-24'));

    expect($manager)->toBe(Exchange::getFacadeRoot())
        ->and($manager->getDefaultDriver())->toBe('ecb')
        ->and($result->stored)->toBe(1)
        ->and(CurrencyRate::query()->pair('EUR', 'GBP')->value('rate'))->toBe('0.85');
});
