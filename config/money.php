<?php

declare(strict_types=1);
use RoundlyConsulting\Money\Models\CurrencyRate;

return [

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    |
    | The currency used when an amount arrives without one: Request::money()
    | without a currency argument, the parser when the input carries no currency
    | token (or a symbol several countries write, such as $, that this currency
    | writes), and AsMoney::configCurrency('money.default_currency').
    |
    */

    'default_currency' => env('MONEY_DEFAULT_CURRENCY', 'EUR'),

    /*
    |--------------------------------------------------------------------------
    | Currencies
    |--------------------------------------------------------------------------
    |
    | `iso` seeds the registry with the bundled ISO 4217 list (set false for a
    | points-only app). `custom` registers extra currencies — exponent 0..18:
    |
    |   'PTS' => ['exponent' => 0, 'name' => 'Loyalty points', 'symbol' => 'pts'],
    |   'BTC' => ['exponent' => 8, 'symbol' => '₿'],
    |   'ETH' => ['exponent' => 18, 'symbol' => 'Ξ'],
    |
    */

    'currencies' => [
        'iso' => env('MONEY_ISO_CURRENCIES', true),
        'custom' => [],

        // Allow-list for currencies chosen by input: the CurrencyCode rule (currency_code)
        // and MoneyAmount::inCurrencyFrom() (null = the whole registry). It never restricts
        // reading stored data.
        'allowed' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema
    |--------------------------------------------------------------------------
    |
    | Read when migrations run, then fixed: `currency_length` is the varchar
    | length of currency columns (3..10, and the longest custom code the
    | registry accepts); `precision` is P of the decimal(P, 0) amount columns
    | $table->money() creates (19..65) — the cast and the MoneyAmount rule
    | enforce the same limit.
    |
    */

    'schema' => [
        'currency_length' => (int) env('MONEY_CURRENCY_LENGTH', 3),
        'precision' => (int) env('MONEY_PRECISION', 38),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rounding
    |--------------------------------------------------------------------------
    |
    | The service-level default rounding mode (formatter digit reduction,
    | avgMoney). Value objects never read config: their lossy methods default
    | to half_away_from_zero as a constant. One of: half_away_from_zero,
    | half_towards_zero, half_even, half_odd, towards_zero, away_from_zero,
    | positive_infinity, negative_infinity.
    |
    */

    'rounding' => env('MONEY_ROUNDING', 'half_away_from_zero'),

    /*
    |--------------------------------------------------------------------------
    | Formatting
    |--------------------------------------------------------------------------
    |
    | `driver`: auto (intl when ext-intl is loaded, else the deterministic
    | decimal formatter) | intl | decimal. `locale`: null follows the app
    | locale. `display`: symbol | code | none. `fallback` configures the
    | deterministic formatter (placeholders {sign} {amount} {code} {symbol});
    | its decimal separator is also the parser's separator without intl.
    |
    */

    'formatting' => [
        'driver' => env('MONEY_FORMATTER', 'auto'),
        'locale' => env('MONEY_LOCALE'),
        'display' => 'symbol',
        'fallback' => [
            'pattern' => '{sign}{amount} {code}',
            'decimal_separator' => '.',
            'thousands_separator' => ',',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Macros
    |--------------------------------------------------------------------------
    |
    | Each DX surface can be switched off. An existing host macro with the same
    | name always wins (registration is hasMacro-guarded).
    |
    */

    'macros' => [
        'collection' => true,
        'request' => true,
        'blade' => true,
        'validation' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Exchange rates
    |--------------------------------------------------------------------------
    |
    | Arithmetic never converts implicitly; conversion goes through these
    | drivers: config (static rates), database (the money_exchange_rates table
    | — publish money-migrations first), ecb (European Central Bank, no setup)
    | and chain (tries `chain` in order). Production recipe: publish the
    | migrations, enable the refresh schedule, set MONEY_EXCHANGE_DRIVER=chain.
    |
    */

    'exchange' => [
        'default' => env('MONEY_EXCHANGE_DRIVER', 'ecb'),
        'chain' => ['database', 'ecb'],
        'pivot' => 'EUR',
        'rounding' => env('MONEY_EXCHANGE_ROUNDING', 'half_even'),
        // "Today" for undated lookups and manual rates. A requested date is always read as
        // its own calendar day (Y-m-d in its own timezone), never shifted into this one.
        'timezone' => env('MONEY_EXCHANGE_TIMEZONE', 'Europe/Berlin'),
        'max_age_days' => (int) env('MONEY_EXCHANGE_MAX_AGE_DAYS', 7),

        'cache' => [
            'enabled' => env('MONEY_EXCHANGE_CACHE', true),
            'store' => env('MONEY_EXCHANGE_CACHE_STORE'),
            'ttl' => (int) env('MONEY_EXCHANGE_CACHE_TTL', 3600),
            'prefix' => 'money:exchange',
        ],

        'providers' => [
            'config' => [
                // Base-keyed decimal strings: ['EUR' => ['USD' => '1.0854']].
                'rates' => [],
            ],
            'database' => [
                'table' => env('MONEY_EXCHANGE_TABLE', 'money_exchange_rates'),
                'model' => env('MONEY_EXCHANGE_MODEL', CurrencyRate::class),
            ],
            'ecb' => [
                'daily_url' => 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml',
                'recent_url' => 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml',
                'history_url' => 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml',
                'timeout' => (int) env('MONEY_ECB_TIMEOUT', 10),
                'retries' => 2,
                'max_bytes' => 33554432,
                'cache_ttl' => 3600,
            ],
        ],

        'refresh' => [
            // ECB publishes around 16:00 CET on TARGET working days.
            'schedule' => env('MONEY_EXCHANGE_SCHEDULE', false),
            'cron' => '30 16 * * 1-5',
            'timezone' => 'Europe/Berlin',
            'source' => 'ecb',
        ],
    ],

];
