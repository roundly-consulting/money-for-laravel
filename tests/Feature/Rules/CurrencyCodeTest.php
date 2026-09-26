<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Facades\Currencies;
use RoundlyConsulting\Money\Rules\CurrencyCode;

function currencyErrors(mixed $value, CurrencyCode $rule): array
{
    return Validator::make(['currency' => $value], ['currency' => [$rule]])->errors()->get('currency');
}

it('accepts registered codes and rejects the rest', function (): void {
    expect(currencyErrors('EUR', new CurrencyCode))->toBe([])
        ->and(currencyErrors('eur', new CurrencyCode))->toBe([])
        ->and(currencyErrors('XYZ', new CurrencyCode))->toBe(['The currency field must be a supported currency.'])
        ->and(currencyErrors(978, new CurrencyCode))->toBe(['The currency field must be a supported currency.']);
});

it('honours an allow-list, the config allow-list and ISO-only', function (): void {
    config(['money.schema.currency_length' => 10]);
    Currencies::register(Currency::custom('PTS', 0));

    expect(currencyErrors('USD', new CurrencyCode(['EUR', 'czk'])))->toHaveCount(1)
        ->and(currencyErrors('CZK', new CurrencyCode(['EUR', 'czk'])))->toBe([])
        ->and(currencyErrors('PTS', new CurrencyCode))->toBe([])
        ->and(currencyErrors('PTS', (new CurrencyCode)->isoOnly()))->toHaveCount(1);

    config(['money.currencies.allowed' => ['EUR']]);

    expect(currencyErrors('USD', new CurrencyCode))->toHaveCount(1)
        ->and(currencyErrors('EUR', new CurrencyCode))->toBe([]);
});
