<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use RoundlyConsulting\Money\Contracts\MoneyFormatter;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyValue;
use RoundlyConsulting\Money\Http\Resources\MoneyResource;
use RoundlyConsulting\Money\Money;

beforeEach(function (): void {
    config(['money.formatting.driver' => 'decimal']);
    app()->forgetInstance(MoneyFormatter::class);
});

it('renders the API shape with a string minor', function (): void {
    $resource = MoneyResource::from(Money::ofMinor(1050, 'EUR'));

    expect($resource?->toArray(new Request))->toBe([
        'minor' => '1050',
        'decimal' => '10.50',
        'currency' => 'EUR',
        'exponent' => 2,
        'formatted' => '10.50 EUR',
    ])->and($resource?->toJson())->toBe('{"minor":"1050","decimal":"10.50","currency":"EUR","exponent":2,"formatted":"10.50 EUR"}');
});

it('returns null for null', function (): void {
    expect(MoneyResource::from(null))->toBeNull();
});

it('changes only formatted when the locale changes', function (): void {
    config(['money.formatting.driver' => 'auto']);
    app()->forgetInstance(MoneyFormatter::class);

    $money = Money::ofMinor(1050, 'EUR');
    $en = MoneyResource::from($money)?->toArray(new Request);

    app()->setLocale('sk');
    $sk = MoneyResource::from($money)?->toArray(new Request);

    expect(array_diff_assoc((array) $en, (array) $sk))->toHaveKey('formatted')->toHaveCount(1);
})->skip(fn (): bool => ! extension_loaded('intl'), 'locale output needs ext-intl');

it('handles custom currencies', function (): void {
    expect(MoneyResource::from(Money::ofMinor(5, Currency::custom('PTS', 0)))?->toArray(new Request)['decimal'])->toBe('5');
});

it('refuses a non-money resource', function (): void {
    (new MoneyResource(5))->toArray(new Request);
})->throws(InvalidMoneyValue::class);
