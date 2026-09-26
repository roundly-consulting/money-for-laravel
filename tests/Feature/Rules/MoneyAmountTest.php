<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Rules\MoneyAmount;

function amountErrors(mixed $value, MoneyAmount $rule, array $extra = []): array
{
    return Validator::make(['price' => $value, ...$extra], ['price' => [$rule]])->errors()->get('price');
}

it('accepts valid amounts', function (mixed $value): void {
    expect(amountErrors($value, MoneyAmount::in('EUR')))->toBe([]);
})->with([12, '12', '12.5', '12.50', '+1', 1.1, 19.99, '0']);

it('rejects malformed amounts, excess precision and negatives', function (mixed $value, string $message): void {
    expect(amountErrors($value, MoneyAmount::in('EUR')))->toBe([$message]);
})->with([
    ['1e3', 'The price field must be a valid amount.'],
    ['1,000.00', 'The price field must be a valid amount.'],
    [true, 'The price field must be a valid amount.'],
    [0.30000000000000004, 'The price field must have at most 2 decimal places.'],
    ['10.005', 'The price field must have at most 2 decimal places.'],
    [1e20, 'The price field must be a valid amount.'],
    ['-1', 'The price field must not be negative.'],
    [str_repeat('9', 40), 'The price field is too large (at most 38 digits).'],
    [str_repeat('9', 70), 'The price field is too large (at most 38 digits).'],
]);

it('checks bounds, sign and currency', function (): void {
    expect(amountErrors('0', MoneyAmount::in('EUR')->positive()))->toBe(['The price field must be greater than zero.'])
        ->and(amountErrors('-5', MoneyAmount::in('EUR')->allowNegative()))->toBe([])
        ->and(amountErrors('0.00', MoneyAmount::in('EUR')->min('0.01')))->toBe(['The price field must be at least 0.01.'])
        ->and(amountErrors('100000', MoneyAmount::in('EUR')->max('99999.99')))->toBe(['The price field must not be greater than 99999.99.'])
        ->and(amountErrors('5', MoneyAmount::in('EUR')->min(Money::ofMinor(500, 'EUR'))->max(5)))->toBe([])
        ->and(amountErrors('5', MoneyAmount::in('XYZ')))->toBe(['The price field must be a supported currency.']);
});

it('reads the currency from a sibling field', function (): void {
    $rule = fn () => MoneyAmount::inCurrencyFrom('currency');

    expect(amountErrors('1234', $rule(), ['currency' => 'JPY']))->toBe([])
        ->and(amountErrors('12.34', $rule(), ['currency' => 'JPY']))->toBe(['The price field must have at most 0 decimal places.'])
        ->and(amountErrors('1.234', $rule(), ['currency' => 'BHD']))->toBe([])
        ->and(amountErrors('1', $rule(), []))->toBe(['The price field must be a supported currency.']);
});

it('parses localized input when asked', function (): void {
    expect(amountErrors('1 234,50', MoneyAmount::in('EUR')->localized('sk')))->toBe([])
        ->and(amountErrors('1.23.4', MoneyAmount::in('EUR')->localized('en')))->toBe(['The price field must be a valid amount.'])
        ->and(amountErrors('1 234,50', MoneyAmount::in('EUR')))->toBe(['The price field must be a valid amount.']);
})->skip(fn (): bool => ! extension_loaded('intl'), 'locale symbols need ext-intl');

it('translates messages to Slovak', function (): void {
    app()->setLocale('sk');

    expect(amountErrors('-1', MoneyAmount::in('EUR')))->toBe(['Pole price nesmie byť záporné.']);
});
