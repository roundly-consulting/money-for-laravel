<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Money\Casts\AsCurrency;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyValue;
use RoundlyConsulting\Money\Exceptions\UnknownCurrency;

function currencyModel(): Model
{
    return new class extends Model
    {
        protected $guarded = [];

        protected function casts(): array
        {
            return ['currency' => AsCurrency::class];
        }
    };
}

it('reads a code column as a registered Currency', function (): void {
    $model = currencyModel()->setRawAttributes(['currency' => 'CZK']);

    expect($model->currency)->toBeInstanceOf(Currency::class)
        ->and($model->currency->code)->toBe('CZK');
});

it('maps null both ways', function (): void {
    $model = currencyModel();
    $model->currency = null;

    expect($model->getAttributes())->toBe(['currency' => null])
        ->and($model->currency)->toBeNull();
});

it('writes codes and registered currencies', function (): void {
    $model = currencyModel();

    $model->currency = 'usd';
    expect($model->getAttributes()['currency'])->toBe('USD');

    $model->currency = Currency::of('EUR');
    expect($model->getAttributes()['currency'])->toBe('EUR');
});

it('refuses unknown codes, ad-hoc currencies and other types', function (): void {
    $model = currencyModel();

    expect(function () use ($model): void {
        $model->currency = 'XYZ';
    })->toThrow(UnknownCurrency::class)
        ->and(function () use ($model): void {
            $model->currency = new Currency('EUR', 3, 'Fake euro');
        })->toThrow(InvalidMoneyValue::class)
        ->and(function () use ($model): void {
            $model->currency = Currency::custom('PTS', 0);
        })->toThrow(InvalidMoneyValue::class)
        ->and(function () use ($model): void {
            $model->currency = 978;
        })->toThrow(InvalidMoneyValue::class);
});

it('refuses a corrupt stored value', function (): void {
    currencyModel()->setRawAttributes(['currency' => 978])->currency;
})->throws(InvalidMoneyValue::class);
