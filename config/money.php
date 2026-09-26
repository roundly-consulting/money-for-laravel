<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    |
    | The currency used when an amount arrives without one: Request::money()
    | without a currency argument, the parser when the input carries no currency
    | token, and AsMoney::configCurrency('money.default_currency').
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
        'iso' => (bool) env('MONEY_ISO_CURRENCIES', true),
        'custom' => [],

        // Default allow-list for the CurrencyCode / money_amount input rules (null = the
        // whole registry). It never restricts reading stored data.
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

];
