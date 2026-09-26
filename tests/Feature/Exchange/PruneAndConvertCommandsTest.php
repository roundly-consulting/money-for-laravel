<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
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
