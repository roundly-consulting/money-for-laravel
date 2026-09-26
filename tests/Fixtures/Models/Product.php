<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Money\Casts\AsCurrency;
use RoundlyConsulting\Money\Casts\AsMoney;
use RoundlyConsulting\Money\Casts\AsMoneyJson;

final class Product extends Model
{
    protected $table = 'money_products';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => AsMoney::class,
            'compare_at_price' => AsMoney::currencyColumn('currency'),
            'sale_price' => AsMoney::currencyColumn('currency'),
            'currency' => AsCurrency::class,
            'budget' => AsMoney::fixedCurrency('EUR'),
            'store_credit' => AsMoney::configCurrency('money.default_currency'),
            'total' => AsMoney::attributeCurrency('shop_currency'),
            'shop_currency' => AsCurrency::class,
            'snapshot' => AsMoneyJson::class,
            'legacy_amount' => AsMoney::currencyColumn('legacy_currency'),
        ];
    }
}
