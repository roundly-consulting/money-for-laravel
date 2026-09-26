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

];
