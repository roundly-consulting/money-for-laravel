<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Money\Models\CurrencyRate;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Berlin'));

    CurrencyRate::factory()->pair('EUR', 'USD', '1.1')->on('2026-09-01')->from('ecb')->create();
    CurrencyRate::factory()->pair('EUR', 'USD', '1.2')->on('2026-09-02')->from('manual')->create();
    CurrencyRate::factory()->pair('EUR', 'USD', '1.3')->on('2026-09-24')->from('ecb')->create();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('prunes old source rows and keeps manual ones', function (): void {
    $this->artisan('money:rates:prune', ['--before' => '2026-09-10', '--pretend' => true])
        ->expectsOutputToContain('Would delete 1')
        ->assertSuccessful();

    expect(CurrencyRate::query()->count())->toBe(3);

    $this->artisan('money:rates:prune', ['--keep-days' => '10'])->expectsOutputToContain('Deleted 1')->assertSuccessful();

    expect(CurrencyRate::query()->pluck('source')->sort()->values()->all())->toBe(['ecb', 'manual']);

    $this->artisan('money:rates:prune', ['--before' => '2026-09-10', '--include-manual' => true])->assertSuccessful();

    expect(CurrencyRate::query()->count())->toBe(1);
});

it('requires a bound', function (): void {
    $this->artisan('money:rates:prune')->assertExitCode(2);
});

it('converts from the command with rate details', function (): void {
    config(['money.exchange.providers.config.rates' => ['EUR' => ['USD' => '1.0854']]]);

    $this->artisan('money:convert', ['amount' => '10', 'from' => 'EUR', 'to' => 'USD', '--driver' => 'config'])
        ->expectsOutputToContain('1.0854000000')
        ->expectsOutputToContain('config')
        ->expectsOutputToContain('10.85 USD')
        ->assertSuccessful();

    $this->artisan('money:convert', ['amount' => '10', 'from' => 'EUR', 'to' => 'USD', '--driver' => 'database', '--date' => '2026-09-24'])
        ->expectsOutputToContain('13.00 USD')
        ->expectsOutputToContain('2026-09-24')
        ->assertSuccessful();
});

it('converts on the --date calendar day, whatever the app timezone', function (string $appTimezone): void {
    config(['app.timezone' => $appTimezone]);
    date_default_timezone_set($appTimezone);

    try {
        CurrencyRate::factory()->pair('EUR', 'USD', '1.4')->on('2026-09-23')->from('ecb')->create();

        $this->artisan('money:convert', ['amount' => '10', 'from' => 'EUR', 'to' => 'USD', '--driver' => 'database', '--date' => '2026-09-24'])
            ->expectsOutputToContain('2026-09-24')
            ->expectsOutputToContain('13.00 USD')
            ->assertSuccessful();
    } finally {
        date_default_timezone_set('UTC');
    }
})->with(['Europe/Helsinki', 'Asia/Tokyo']);

it('refuses dates that are not real Y-m-d dates instead of shifting or crashing', function (string $command, array $arguments): void {
    // 2026-02-30 used to roll over to 2026-03-02 (pruning two extra days); 2026/09/10 used
    // to crash with a Carbon format exception.
    Http::preventStrayRequests();

    $this->artisan($command, $arguments)->assertExitCode(2);

    expect(CurrencyRate::query()->count())->toBe(3);
})->with([
    'prune overflow' => ['money:rates:prune', ['--before' => '2026-09-31']],
    'prune slashes' => ['money:rates:prune', ['--before' => '2026/09/10']],
    'prune keep-days junk' => ['money:rates:prune', ['--keep-days' => '10d']],
    'convert overflow' => ['money:convert', ['amount' => '10', 'from' => 'EUR', 'to' => 'USD', '--date' => '2026-02-30']],
    'refresh from overflow' => ['money:rates:refresh', ['--from' => '2026-02-30']],
    'refresh to junk' => ['money:rates:refresh', ['--to' => 'yesterday']],
]);
