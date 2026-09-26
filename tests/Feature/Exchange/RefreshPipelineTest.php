<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Money\Actions\RefreshExchangeRatesAction;
use RoundlyConsulting\Money\Actions\StoreExchangeRatesAction;
use RoundlyConsulting\Money\Commands\RefreshExchangeRatesCommand;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\DataTransferObjects\RefreshExchangeRatesData;
use RoundlyConsulting\Money\Enums\EcbFeed;
use RoundlyConsulting\Money\Events\ExchangeRatesRefreshed;
use RoundlyConsulting\Money\Events\ExchangeRatesRefreshFailed;
use RoundlyConsulting\Money\Exceptions\ExchangeRateFetchFailed;
use RoundlyConsulting\Money\Exceptions\InvalidExchangeRate;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Jobs\RefreshExchangeRates;
use RoundlyConsulting\Money\Models\CurrencyRate;
use RoundlyConsulting\Money\MoneyServiceProvider;
use RoundlyConsulting\Money\Ratio;

function feed(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../../Fixtures/ecb/'.$name.'.xml');
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-25 16:30', 'Europe/Berlin'));

    if (! str_contains((string) test()->name(), 'failure')) {
        Http::fake([
            '*eurofxref-daily.xml' => Http::response(feed('daily')),
            '*eurofxref-hist-90d.xml' => Http::response(feed('hist-90d-trimmed')),
            '*eurofxref-hist.xml' => Http::response(feed('hist-trimmed')),
        ]);
    }
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('stores the daily feed idempotently and fires an event', function (): void {
    Event::fake([ExchangeRatesRefreshed::class]);

    $result = app(RefreshExchangeRatesAction::class)->execute(new RefreshExchangeRatesData);
    app(RefreshExchangeRatesAction::class)->execute(new RefreshExchangeRatesData);

    expect($result->source)->toBe('ecb')
        ->and($result->stored)->toBe(29)
        ->and($result->skippedManual)->toBe(0)
        ->and($result->skippedUnknownCurrency)->toBe(0)
        ->and($result->latestDate?->toDateString())->toBe('2026-09-25')
        ->and(CurrencyRate::query()->count())->toBe(29)
        ->and(CurrencyRate::query()->pair('EUR', 'USD')->value('rate'))->toBe('1.1403');

    Event::assertDispatchedTimes(ExchangeRatesRefreshed::class, 2);
});

it('stores the 90-day and history feeds within the window', function (): void {
    $recent = app(RefreshExchangeRatesAction::class)->execute(new RefreshExchangeRatesData(feed: EcbFeed::Recent));
    $history = app(RefreshExchangeRatesAction::class)->execute(new RefreshExchangeRatesData(feed: EcbFeed::History, from: CarbonImmutable::parse('2026-09-17')));

    expect($recent->stored)->toBe(29 * 4)
        ->and($history->stored)->toBe(29 * 2)
        ->and(CurrencyRate::query()->count())->toBe(29 * 6);
});

it('protects manual rows from source rows, but lets manual overwrite manual', function (): void {
    $store = app(StoreExchangeRatesAction::class);
    $date = CarbonImmutable::parse('2026-09-25');

    $store->execute([ExchangeRate::fromDecimal('EUR', 'USD', '1.20', $date, 'manual')]);

    $result = app(RefreshExchangeRatesAction::class)->execute(new RefreshExchangeRatesData);

    expect($result->skippedManual)->toBe(1)
        ->and($result->stored)->toBe(28)
        ->and(CurrencyRate::query()->pair('EUR', 'USD')->value('rate'))->toBe('1.2');

    $manual = $store->execute([ExchangeRate::fromDecimal('EUR', 'USD', '1.25', $date, 'manual')]);

    expect($manual->source)->toBe('manual')
        ->and($manual->stored)->toBe(1)
        ->and(CurrencyRate::query()->pair('EUR', 'USD')->value('rate'))->toBe('1.25')
        ->and(CurrencyRate::query()->pair('EUR', 'USD')->value('source'))->toBe('manual');
});

it('never overwrites a manual row saved between the protection check and the write', function (): void {
    $date = CarbonImmutable::parse('2026-09-25');
    CurrencyRate::factory()->pair('EUR', 'CZK', '25.0')->on('2026-09-25')->from('ecb')->create();

    // Another process saves a manual rate right after the action looked for manual rows.
    $raced = false;

    DB::listen(function (QueryExecuted $query) use (&$raced): void {
        if (! $raced && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'money_exchange_rates')) {
            $raced = true;

            CurrencyRate::factory()->pair('EUR', 'USD', '1.20')->on('2026-09-25')->from('manual')->create();
        }
    });

    app(StoreExchangeRatesAction::class)->execute([
        ExchangeRate::fromDecimal('EUR', 'USD', '1.1403', $date, 'ecb'),
        ExchangeRate::fromDecimal('EUR', 'CZK', '24.345', $date, 'ecb'),
        ExchangeRate::fromDecimal('EUR', 'GBP', '0.8566', $date, 'ecb'),
    ]);

    $row = fn (string $quote): ?CurrencyRate => CurrencyRate::query()->pair('EUR', $quote)->first();

    expect($raced)->toBeTrue()
        ->and($row('USD')?->rate)->toBe('1.20')
        ->and($row('USD')?->source)->toBe('manual')
        ->and($row('CZK')?->rate)->toBe('24.345')
        ->and($row('GBP')?->rate)->toBe('0.8566');
});

it('reports the latest date of the rows it actually wrote', function (): void {
    $store = app(StoreExchangeRatesAction::class);

    $store->execute([ExchangeRate::fromDecimal('EUR', 'USD', '1.20', CarbonImmutable::parse('2026-09-25'), 'manual')]);

    $result = $store->execute([
        ExchangeRate::fromDecimal('EUR', 'USD', '1.1403', CarbonImmutable::parse('2026-09-25'), 'ecb'),
        ExchangeRate::fromDecimal('EUR', 'USD', '1.1301', CarbonImmutable::parse('2026-09-24'), 'ecb'),
    ]);

    expect($result->skippedManual)->toBe(1)
        ->and($result->stored)->toBe(1)
        ->and($result->latestDate?->toDateString())->toBe('2026-09-24');

    $none = $store->execute([ExchangeRate::fromDecimal('EUR', 'USD', '1.1403', CarbonImmutable::parse('2026-09-25'), 'ecb')]);

    expect($none->stored)->toBe(0)
        ->and($none->skippedManual)->toBe(1)
        ->and($none->latestDate)->toBeNull();
});

it('refuses rates that cannot be stored exactly', function (): void {
    app(StoreExchangeRatesAction::class)->execute([
        new ExchangeRate(Currency::of('EUR'), Currency::of('USD'), Ratio::of(1, 3), CarbonImmutable::now(), 'manual'),
    ]);
})->throws(InvalidExchangeRate::class, 'exact decimal');

it('handles an empty batch', function (): void {
    $result = app(StoreExchangeRatesAction::class)->execute([]);

    expect($result->stored)->toBe(0)
        ->and($result->source)->toBe('manual')
        ->and($result->latestDate)->toBeNull();
});

it('fires a failure event, rethrows and keeps existing rows', function (): void {
    CurrencyRate::factory()->pair('EUR', 'USD', '1.1')->on('2026-09-24')->create();
    Http::fake(['*' => Http::response('', 500)]);
    Event::fake([ExchangeRatesRefreshFailed::class]);

    expect(fn () => app(RefreshExchangeRatesAction::class)->execute(new RefreshExchangeRatesData))->toThrow(ExchangeRateFetchFailed::class)
        ->and(CurrencyRate::query()->count())->toBe(1);

    Event::assertDispatched(ExchangeRatesRefreshFailed::class, fn (ExchangeRatesRefreshFailed $event): bool => $event->source === 'ecb' && str_contains($event->message, '500'));
});

it('refreshes from the command and prints the result', function (): void {
    $this->artisan('money:rates:refresh')
        ->expectsOutputToContain('Stored')
        ->expectsOutputToContain('2026-09-25')
        ->assertSuccessful();

    $this->artisan('money:rates:refresh', ['source' => 'ecb', '--feed' => 'recent', '--from' => '2026-09-24', '--to' => '2026-09-25'])
        ->assertSuccessful();

    expect(CurrencyRate::query()->count())->toBe(29 * 2);
});

it('rejects an unknown feed and a non-source driver', function (): void {
    $this->artisan('money:rates:refresh', ['--feed' => 'weekly'])->assertExitCode(2);

    expect(fn () => $this->artisan('money:rates:refresh', ['source' => 'database'])->run())
        ->toThrow(InvalidMoneyConfiguration::class);
});

it('queues a unique job instead when asked', function (): void {
    Queue::fake();

    $this->artisan('money:rates:refresh', ['--queue' => true, '--feed' => 'recent', '--from' => '2026-09-24'])->assertSuccessful();

    Queue::assertPushed(RefreshExchangeRates::class, fn (RefreshExchangeRates $job): bool => $job->uniqueId() === 'ecb:recent'
        && $job->uniqueFor === 600
        && $job->from === '2026-09-24'
        && $job->to === null);
});

it('runs the job through the action', function (): void {
    (new RefreshExchangeRates('ecb', 'recent', '2026-09-25', '2026-09-25'))->handle(app(RefreshExchangeRatesAction::class));
    (new RefreshExchangeRates)->handle(app(RefreshExchangeRatesAction::class));

    expect(CurrencyRate::query()->count())->toBe(29);
});

it('is isolatable', function (): void {
    expect(new RefreshExchangeRatesCommand)->toBeInstanceOf(Isolatable::class);
});

it('registers the refresh schedule only when enabled', function (): void {
    $events = fn (): array => array_values(array_filter(
        app(Schedule::class)->events(),
        fn ($event): bool => str_contains((string) $event->command, 'money:rates:refresh'),
    ));

    expect($events())->toBe([]);

    config(['money.exchange.refresh.schedule' => true]);
    $provider = new MoneyServiceProvider(app());
    $provider->register();
    $provider->boot();

    $scheduled = $events();

    expect($scheduled)->toHaveCount(1)
        ->and($scheduled[0]->expression)->toBe('30 16 * * 1-5')
        ->and($scheduled[0]->timezone)->toBe('Europe/Berlin')
        ->and($scheduled[0]->withoutOverlapping)->toBeTrue()
        ->and($scheduled[0]->onOneServer)->toBeTrue()
        ->and((string) $scheduled[0]->command)->toContain('ecb');
});
