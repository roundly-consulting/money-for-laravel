<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Money\Currencies\DefaultCurrencyRegistry;
use RoundlyConsulting\Money\Exchange\ExchangeManager;
use RoundlyConsulting\Money\Exchange\Providers\CachingExchangeRateProvider;
use RoundlyConsulting\Money\Exchange\RateCacheGeneration;
use RoundlyConsulting\Money\MoneyServiceProvider;

/**
 * env() only turns 'true'/'false' into booleans: a .env "1"/"on"/"yes" stays a string
 * (so `=== true` reads it as off, `=== false` reads "0" as on) and `(bool) 'off'` is
 * true. Every switch must read these the way a host means them — and the about row must
 * agree with the behaviour.
 */
dataset('money env truthy', ['1', 'on', 'yes', 'true']);
dataset('money env falsy', ['0', 'off', 'no', 'false']);

function aboutMoney(): string
{
    Artisan::call('about', ['--only' => 'money']);

    return Artisan::output();
}

/**
 * @return list<object>
 */
function scheduledRefreshes(): array
{
    return array_values(array_filter(
        app(Schedule::class)->events(),
        fn ($event): bool => str_contains((string) $event->command, 'money:rates:refresh'),
    ));
}

function rebootMoney(): void
{
    $provider = new MoneyServiceProvider(app());
    $provider->register();
    $provider->boot();
}

/**
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function moneyConfigWithEnv(array $env): array
{
    foreach ($env as $name => $value) {
        $_SERVER[$name] = $value;
    }

    try {
        return require __DIR__.'/../../config/money.php';
    } finally {
        foreach (array_keys($env) as $name) {
            unset($_SERVER[$name]);
        }
    }
}

it('schedules the rate refresh for an env-style truthy string', function (string $value): void {
    config(['money.exchange.refresh.schedule' => $value]);

    rebootMoney();

    expect(scheduledRefreshes())->toHaveCount(1)
        ->and(aboutMoney())->toContain('30 16 * * 1-5 (Europe/Berlin)');
})->with('money env truthy');

it('does not schedule the rate refresh for an env-style falsy string', function (string $value): void {
    config(['money.exchange.refresh.schedule' => $value]);

    rebootMoney();

    expect(scheduledRefreshes())->toBe([])
        ->and(aboutMoney())->toMatch('/Rates schedule\s*\.*\s*off/');
})->with('money env falsy');

it('reads the exchange cache switch as a boolean', function (string $value, bool $cached): void {
    config(['money.exchange.cache.enabled' => $value]);

    expect((new ExchangeManager(app()))->provider('ecb') instanceof CachingExchangeRateProvider)->toBe($cached)
        ->and(RateCacheGeneration::configured(app('cache')) !== null)->toBe($cached);
})->with([
    '1' => ['1', true],
    'on' => ['on', true],
    '0' => ['0', false],
    'off' => ['off', false],
    'no' => ['no', false],
]);

it('reads the iso currencies switch as a boolean', function (string $value, bool $iso): void {
    config(['money.currencies.iso' => $value]);

    expect(DefaultCurrencyRegistry::fromConfig()->iso() !== [])->toBe($iso);
})->with([
    '1' => ['1', true],
    'yes' => ['yes', true],
    '0' => ['0', false],
    'off' => ['off', false],
]);

it('keeps the shipped config from turning an env "off" into true', function (string $value): void {
    $config = moneyConfigWithEnv([
        'MONEY_EXCHANGE_SCHEDULE' => $value,
        'MONEY_EXCHANGE_CACHE' => $value,
        'MONEY_ISO_CURRENCIES' => $value,
    ]);
    config(['money' => $config]);

    rebootMoney();

    expect(scheduledRefreshes())->toBe([])
        ->and(aboutMoney())->toMatch('/Rates schedule\s*\.*\s*off/')
        ->and(RateCacheGeneration::configured(app('cache')))->toBeNull()
        ->and(DefaultCurrencyRegistry::fromConfig()->iso())->toBe([]);
})->with('money env falsy');

it('reads the macro switches as booleans', function (): void {
    $macros = new ReflectionProperty(Request::class, 'macros');
    $original = $macros->getValue();
    $withoutMoney = $original;
    unset($withoutMoney['money']);

    try {
        $macros->setValue(null, $withoutMoney);
        config(['money.macros.request' => 'off']);
        rebootMoney();

        expect(Request::hasMacro('money'))->toBeFalse();

        config(['money.macros.request' => '1']);
        rebootMoney();

        expect(Request::hasMacro('money'))->toBeTrue();
    } finally {
        $macros->setValue(null, $original);
    }
});
