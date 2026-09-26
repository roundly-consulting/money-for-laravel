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
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema
    |--------------------------------------------------------------------------
    |
    | Read when migrations run, then fixed: `currency_length` is the varchar
    | length of currency columns (and the longest custom code the registry
    | accepts).
    |
    */

    'schema' => [
        'currency_length' => (int) env('MONEY_CURRENCY_LENGTH', 3),
    ],

];
