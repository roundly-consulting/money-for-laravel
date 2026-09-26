<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;

it('exposes currency_code and money_amount string rules', function (): void {
    $validator = Validator::make(
        ['currency' => 'XYZ', 'price' => '10.555', 'fee' => '5', 'limited' => 'USD', 'plain' => '1.5'],
        ['currency' => 'currency_code', 'price' => 'money_amount:EUR', 'fee' => 'money_amount:JPY', 'limited' => 'currency_code:EUR,CZK', 'plain' => 'money_amount'],
    );

    expect($validator->errors()->toArray())->toBe([
        'currency' => ['The currency field must be a supported currency.'],
        'price' => ['The price field must have at most 2 decimal places.'],
        'limited' => ['The limited field must be a supported currency.'],
    ]);
});
