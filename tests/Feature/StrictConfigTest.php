<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use RoundlyConsulting\Money\Currencies\DefaultCurrencyRegistry;
use RoundlyConsulting\Money\Enums\FormatterDriver;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exchange\ExchangeManager;
use RoundlyConsulting\Money\Exchange\RateCacheGeneration;
use RoundlyConsulting\Money\Facades\Exchange;
use RoundlyConsulting\Money\Formatting\DecimalMoneyFormatter;
use RoundlyConsulting\Money\Formatting\Locales;
use RoundlyConsulting\Money\Models\CurrencyRate;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\MoneyServiceProvider;
use RoundlyConsulting\Money\Parsing\LocalizedMoneyParser;
use RoundlyConsulting\Money\Rules\CurrencyCode;
use RoundlyConsulting\Money\Rules\MoneyAmount;
use RoundlyConsulting\Money\Support\MoneyConfig;
use RoundlyConsulting\Money\Support\Schema;

/**
 * Owner rule: a typo in a host's config or env fails loudly and never falls back silently.
 * A key that is not set — absent, null or blank (`''` or whitespace, a host's `KEY=`) — takes
 * its documented default; a present value of the wrong shape throws InvalidMoneyConfiguration
 * naming the key — junk is never cast to 0 or swapped for the default.
 */

/**
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function strictMoneyConfigWithEnv(array $env): array
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

function freshExchange(): ExchangeManager
{
    return new ExchangeManager(app());
}

it('hands integer env values through raw (strict config)', function (): void {
    $config = strictMoneyConfigWithEnv([
        'MONEY_CURRENCY_LENGTH' => 'five',
        'MONEY_PRECISION' => '5.5',
        'MONEY_EXCHANGE_MAX_AGE_DAYS' => '1e3',
        'MONEY_EXCHANGE_CACHE_TTL' => 'hour',
        'MONEY_ECB_TIMEOUT' => '10s',
    ]);

    expect($config['schema']['currency_length'])->toBe('five')
        ->and($config['schema']['precision'])->toBe('5.5')
        ->and($config['exchange']['max_age_days'])->toBe('1e3')
        ->and($config['exchange']['cache']['ttl'])->toBe('hour')
        ->and($config['exchange']['providers']['ecb']['timeout'])->toBe('10s');
});

it('reads canonical integer env strings (strict config)', function (): void {
    config(['money' => strictMoneyConfigWithEnv([
        'MONEY_CURRENCY_LENGTH' => '4',
        'MONEY_PRECISION' => ' 40 ',
        'MONEY_EXCHANGE_MAX_AGE_DAYS' => '0',
        'MONEY_EXCHANGE_CACHE_TTL' => '60',
        'MONEY_ECB_TIMEOUT' => '30',
    ])]);

    expect(Schema::currencyLength())->toBe(4)
        ->and(Schema::precision())->toBe(40)
        ->and(MoneyConfig::maxAgeDays())->toBe(0)
        ->and(MoneyConfig::cacheTtl())->toBe(60)
        ->and(MoneyConfig::ecbTimeout())->toBe(30);
});

it('falls back to the documented defaults only for absent keys (strict config)', function (): void {
    foreach ([
        'money.default_currency', 'money.schema.currency_length', 'money.schema.precision',
        'money.exchange.default', 'money.exchange.pivot', 'money.exchange.chain',
        'money.exchange.timezone', 'money.exchange.max_age_days', 'money.exchange.cache.store',
        'money.exchange.cache.ttl', 'money.exchange.cache.prefix', 'money.exchange.providers.config.rates',
        'money.exchange.providers.database.table', 'money.exchange.providers.ecb',
        'money.exchange.refresh.source', 'money.exchange.refresh.cron', 'money.exchange.refresh.timezone',
        'money.formatting.locale', 'money.formatting.fallback', 'money.currencies.allowed', 'money.currencies.custom',
    ] as $key) {
        config([$key => null]);
    }

    expect(MoneyConfig::defaultCurrency())->toBe('EUR')
        ->and(Schema::currencyLength())->toBe(3)
        ->and(Schema::precision())->toBe(38)
        ->and(MoneyConfig::exchangeDriver())->toBe('ecb')
        ->and(MoneyConfig::pivot())->toBeNull()
        ->and(MoneyConfig::chain())->toBe([])
        ->and(MoneyConfig::timezone())->toBe('Europe/Berlin')
        ->and(MoneyConfig::maxAgeDays())->toBe(7)
        ->and(MoneyConfig::cacheStore())->toBeNull()
        ->and(MoneyConfig::cacheTtl())->toBe(3600)
        ->and(MoneyConfig::cachePrefix())->toBe('money:exchange')
        ->and(MoneyConfig::configRates())->toBe([])
        ->and(MoneyConfig::table())->toBe('money_exchange_rates')
        ->and(MoneyConfig::ecbUrls())->toBe([
            'daily' => MoneyConfig::ECB_DAILY_URL,
            'recent' => MoneyConfig::ECB_RECENT_URL,
            'history' => MoneyConfig::ECB_HISTORY_URL,
        ])
        ->and(MoneyConfig::ecbTimeout())->toBe(10)
        ->and(MoneyConfig::ecbRetries())->toBe(2)
        ->and(MoneyConfig::ecbMaxBytes())->toBe(33_554_432)
        ->and(MoneyConfig::ecbCacheTtl())->toBe(3600)
        ->and(MoneyConfig::refreshSource())->toBe('ecb')
        ->and(MoneyConfig::refreshCron())->toBe('30 16 * * 1-5')
        ->and(MoneyConfig::refreshTimezone())->toBe('Europe/Berlin')
        ->and(MoneyConfig::locale())->toBeNull()
        ->and(MoneyConfig::fallbackPattern())->toBe('{sign}{amount} {code}')
        ->and(MoneyConfig::decimalSeparator())->toBe('.')
        ->and(MoneyConfig::thousandsSeparator())->toBe(',')
        ->and(MoneyConfig::allowedCurrencies())->toBeNull()
        ->and(MoneyConfig::customCurrencies())->toBe([]);
});

it('refuses a junk integer instead of casting it (strict config)', function (string $key, mixed $value, Closure $read): void {
    config([$key => $value]);

    expect($read)->toThrow(InvalidMoneyConfiguration::class, "[{$key}]");
})->with([
    'currency length "five"' => ['money.schema.currency_length', 'five', fn () => Schema::currencyLength()],
    'precision "5.5"' => ['money.schema.precision', '5.5', fn () => Schema::precision()],
    'max age "five"' => ['money.exchange.max_age_days', 'five', fn () => freshExchange()->provider('database')],
    'cache ttl "1e3"' => ['money.exchange.cache.ttl', '1e3', fn () => freshExchange()->provider('ecb')],
    'ecb timeout "five"' => ['money.exchange.providers.ecb.timeout', 'five', fn () => freshExchange()->source('ecb')],
    'ecb retries true' => ['money.exchange.providers.ecb.retries', true, fn () => freshExchange()->source('ecb')],
    'ecb max bytes "32MB"' => ['money.exchange.providers.ecb.max_bytes', '32MB', fn () => freshExchange()->source('ecb')],
    'ecb cache ttl []' => ['money.exchange.providers.ecb.cache_ttl', [], fn () => freshExchange()->source('ecb')],
]);

it('refuses an integer out of range (strict config)', function (string $key, mixed $value, Closure $read): void {
    config([$key => $value]);

    expect($read)->toThrow(InvalidMoneyConfiguration::class, "[{$key}]");
})->with([
    'currency length 11' => ['money.schema.currency_length', 11, fn () => Schema::currencyLength()],
    'precision 18' => ['money.schema.precision', '18', fn () => Schema::precision()],
    'max age -1' => ['money.exchange.max_age_days', '-1', fn () => freshExchange()->provider('database')],
    'cache ttl 0' => ['money.exchange.cache.ttl', '0', fn () => freshExchange()->provider('ecb')],
    'ecb timeout 0' => ['money.exchange.providers.ecb.timeout', 0, fn () => freshExchange()->source('ecb')],
    'ecb retries -1' => ['money.exchange.providers.ecb.retries', -1, fn () => freshExchange()->source('ecb')],
    'ecb cache ttl 0' => ['money.exchange.providers.ecb.cache_ttl', '0', fn () => freshExchange()->source('ecb')],
]);

it('reads a blank setting as not set, so its documented default applies (strict config)', function (string $blank): void {
    foreach ([
        'money.default_currency', 'money.schema.currency_length', 'money.schema.precision', 'money.rounding',
        'money.formatting.driver', 'money.formatting.display', 'money.exchange.default', 'money.exchange.pivot',
        'money.exchange.chain', 'money.exchange.rounding', 'money.exchange.timezone', 'money.exchange.max_age_days',
        'money.exchange.cache.store', 'money.exchange.cache.ttl', 'money.exchange.cache.prefix',
        'money.exchange.providers.config.rates', 'money.exchange.providers.database.table',
        'money.exchange.providers.ecb.daily_url', 'money.exchange.providers.ecb.timeout',
        'money.exchange.refresh.source', 'money.exchange.refresh.cron', 'money.exchange.refresh.timezone',
        'money.formatting.locale', 'money.formatting.fallback.pattern', 'money.formatting.fallback.decimal_separator',
        'money.currencies.allowed', 'money.currencies.custom',
    ] as $key) {
        config([$key => $blank]);
    }

    expect(MoneyConfig::defaultCurrency())->toBe('EUR')
        ->and(Schema::currencyLength())->toBe(3)
        ->and(Schema::precision())->toBe(38)
        ->and(MoneyConfig::rounding())->toBe(RoundingMode::HalfAwayFromZero)
        ->and(MoneyServiceProvider::formatterDriver(false))->toBe(FormatterDriver::Decimal)
        ->and(MoneyConfig::exchangeDriver())->toBe('ecb')
        ->and(MoneyConfig::pivot())->toBeNull()
        ->and(MoneyConfig::chain())->toBe([])
        ->and(MoneyConfig::exchangeRounding())->toBe(RoundingMode::HalfEven)
        ->and(MoneyConfig::timezone())->toBe('Europe/Berlin')
        ->and(MoneyConfig::maxAgeDays())->toBe(7)
        ->and(MoneyConfig::cacheStore())->toBeNull()
        ->and(MoneyConfig::cacheTtl())->toBe(3600)
        ->and(MoneyConfig::cachePrefix())->toBe('money:exchange')
        ->and(MoneyConfig::configRates())->toBe([])
        ->and(MoneyConfig::table())->toBe('money_exchange_rates')
        ->and(MoneyConfig::ecbUrls()['daily'])->toBe(MoneyConfig::ECB_DAILY_URL)
        ->and(MoneyConfig::ecbTimeout())->toBe(10)
        ->and(MoneyConfig::refreshSource())->toBe('ecb')
        ->and(MoneyConfig::refreshCron())->toBe('30 16 * * 1-5')
        ->and(MoneyConfig::refreshTimezone())->toBe('Europe/Berlin')
        ->and(MoneyConfig::locale())->toBeNull()
        ->and(MoneyConfig::fallbackPattern())->toBe('{sign}{amount} {code}')
        ->and(MoneyConfig::decimalSeparator())->toBe('.')
        ->and(MoneyConfig::allowedCurrencies())->toBeNull()
        ->and(MoneyConfig::customCurrencies())->toBe([])
        ->and((new DecimalMoneyFormatter)->format(Money::ofMajor('1234.50', 'EUR')))->toBe('1,234.50 EUR')
        ->and((new CurrencyRate)->getTable())->toBe('money_exchange_rates')
        ->and(app(LocalizedMoneyParser::class)->parse('12.50')->currency()->code)->toBe('EUR');
})->with(['empty' => '', 'whitespace' => '  ']);

it('still refuses junk once blank reads as not set (strict config)', function (string $key, mixed $value, Closure $read): void {
    config([$key => $value]);

    expect($read)->toThrow(InvalidMoneyConfiguration::class, "[{$key}]");
})->with([
    'precision "five"' => ['money.schema.precision', 'five', fn () => Schema::precision()],
    'rounding typo' => ['money.rounding', 'half_up', fn () => MoneyConfig::rounding()],
    'exchange rounding typo' => ['money.exchange.rounding', 'bankers', fn () => MoneyConfig::exchangeRounding()],
    'driver typo' => ['money.formatting.driver', 'icu', fn () => MoneyServiceProvider::formatterDriver(false)],
    'display typo' => ['money.formatting.display', 'symbols', fn () => (new DecimalMoneyFormatter)->format(Money::ofMajor('1.50', 'EUR'))],
]);

it('refuses a wrong-typed string setting instead of using the default (strict config)', function (string $key, mixed $value, Closure $read): void {
    config([$key => $value]);

    expect($read)->toThrow(InvalidMoneyConfiguration::class, "[{$key}]");
})->with([
    'default driver array' => ['money.exchange.default', ['ecb'], fn () => freshExchange()->getDefaultDriver()],
    'pivot array' => ['money.exchange.pivot', ['EUR'], fn () => freshExchange()->provider('config')],
    'timezone int' => ['money.exchange.timezone', 1, fn () => Exchange::rates()->manual('EUR', 'USD', '1.1')],
    'cache store array' => ['money.exchange.cache.store', ['redis'], fn () => freshExchange()->provider('ecb')],
    'cache prefix int' => ['money.exchange.cache.prefix', 5, fn () => RateCacheGeneration::configured(app('cache'))],
    'table array' => ['money.exchange.providers.database.table', ['rates'], fn () => (new CurrencyRate)->getTable()],
    'ecb url int' => ['money.exchange.providers.ecb.daily_url', 1, fn () => freshExchange()->source('ecb')],
    'default currency array' => ['money.default_currency', ['EUR'], fn () => app(LocalizedMoneyParser::class)->parse('12.50')],
    'locale array' => ['money.formatting.locale', ['en'], fn () => Locales::resolve(null)],
    'pattern int' => ['money.formatting.fallback.pattern', 5, fn () => (new DecimalMoneyFormatter)->format(Money::ofMajor('1.50', 'EUR'))],
    'decimal separator int' => ['money.formatting.fallback.decimal_separator', 0, fn () => (new DecimalMoneyFormatter)->format(Money::ofMajor('1.50', 'EUR'))],
    'thousands separator int' => ['money.formatting.fallback.thousands_separator', 0, fn () => (new DecimalMoneyFormatter)->format(Money::ofMajor('1000', 'EUR'))],
    'refresh cron int' => ['money.exchange.refresh.cron', 5, fn () => MoneyConfig::refreshCron()],
    'chain a string' => ['money.exchange.chain', 'database,ecb', fn () => MoneyConfig::chain()],
    'allowed a string' => ['money.currencies.allowed', 'EUR', fn () => MoneyConfig::allowedCurrencies()],
]);

it('keeps a space or an empty thousands separator as a value, not as unset (strict config)', function (string $separator, string $expected): void {
    config(['money.formatting.fallback.thousands_separator' => $separator]);

    expect((new DecimalMoneyFormatter)->format(Money::ofMajor('1234.50', 'EUR')))->toBe($expected);
})->with([
    'space' => [' ', '1 234.50 EUR'],
    'none' => ['', '1234.50 EUR'],
]);

/**
 * An exception to the fleet's "blank = not set" rule: `''` (no grouping) and `' '` (space
 * grouping) are meaningful separators, so neither falls back to `','`. Only a separator that
 * is not set (absent or null) takes the default.
 */
it('takes the , thousands separator only when the separator is not set (strict config)', function (Closure $leaveUnset): void {
    $leaveUnset();

    expect(MoneyConfig::thousandsSeparator())->toBe(',')
        ->and((new DecimalMoneyFormatter)->format(Money::ofMajor('1234.50', 'EUR')))->toBe('1,234.50 EUR');
})->with([
    'the shipped config' => [static function (): void {}],
    'absent' => [static fn () => config(['money.formatting.fallback' => Arr::except((array) config('money.formatting.fallback'), 'thousands_separator')])],
    'null' => [static fn () => config(['money.formatting.fallback.thousands_separator' => null])],
]);

it('refuses a non-string thousands separator instead of reading it as either answer (strict config)', function (mixed $junk): void {
    config(['money.formatting.fallback.thousands_separator' => $junk]);

    expect(fn () => MoneyConfig::thousandsSeparator())
        ->toThrow(InvalidMoneyConfiguration::class, '[money.formatting.fallback.thousands_separator]');
})->with([
    'false' => [false], 'int' => [0], 'array' => [[',']],
]);

it('refuses a malformed exchange chain instead of skipping entries (strict config)', function (mixed $chain): void {
    config(['money.exchange.chain' => $chain]);

    expect(fn () => freshExchange()->provider('chain'))
        ->toThrow(InvalidMoneyConfiguration::class, '[money.exchange.chain]');
})->with([
    'a string' => ['database,ecb'],
    'a blank entry' => [['database', '']],
    'a non-string entry' => [['database', 1]],
    'itself' => [['database', 'chain']],
    'a map' => [['first' => 'database']],
]);

it('refuses non-array config rates instead of using none (strict config)', function (): void {
    config(['money.exchange.providers.config.rates' => 'EUR:USD=1.1']);

    expect(fn () => freshExchange()->provider('config'))
        ->toThrow(InvalidMoneyConfiguration::class, '[money.exchange.providers.config.rates]');
});

it('refuses a non-list currency allow-list instead of allowing every currency (strict config)', function (mixed $allowed, Closure $validate): void {
    config(['money.currencies.allowed' => $allowed]);

    expect($validate)->toThrow(InvalidMoneyConfiguration::class, '[money.currencies.allowed]');
})->with([
    'string, currency_code' => ['EUR', fn () => Validator::make(['c' => 'USD'], ['c' => [new CurrencyCode]])->passes()],
    'string, money_amount' => ['EUR', fn () => Validator::make(['a' => '1', 'c' => 'USD'], ['a' => [MoneyAmount::inCurrencyFrom('c')]])->passes()],
    'non-string entry' => [['EUR', 1], fn () => Validator::make(['c' => 'USD'], ['c' => [new CurrencyCode]])->passes()],
]);

it('still applies a well-formed allow-list (strict config)', function (): void {
    config(['money.currencies.allowed' => [' eur ']]);

    expect(Validator::make(['c' => 'EUR'], ['c' => [new CurrencyCode]])->passes())->toBeTrue()
        ->and(Validator::make(['c' => 'USD'], ['c' => [new CurrencyCode]])->passes())->toBeFalse();
});

it('refuses non-array custom currencies instead of registering none (strict config)', function (): void {
    config(['money.currencies.custom' => 'PTS']);

    expect(fn () => DefaultCurrencyRegistry::fromConfig())
        ->toThrow(InvalidMoneyConfiguration::class, '[money.currencies.custom]');
});

it('names the full key of a junk custom-currency exponent (strict config)', function (): void {
    config(['money.currencies.custom' => ['PTS' => ['exponent' => 'two']]]);

    expect(fn () => DefaultCurrencyRegistry::fromConfig())
        ->toThrow(InvalidMoneyConfiguration::class, '[money.currencies.custom.PTS.exponent]');
});
