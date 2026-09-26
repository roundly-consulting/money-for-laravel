<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Casts;

use Illuminate\Contracts\Database\Eloquent\Castable;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Money;

/**
 * Cast a minor-unit amount column to {@see Money}.
 *
 * ```php
 * 'price'            => AsMoney::class,                                   // price + price_currency
 * 'compare_at_price' => AsMoney::currencyColumn('currency'),              // shared currency column
 * 'budget'           => AsMoney::fixedCurrency('EUR'),                    // amount column only
 * 'store_credit'     => AsMoney::configCurrency('shops.pricing.default_currency'),
 * 'total'            => AsMoney::attributeCurrency('shop_currency'),      // accessor-derived
 * ```
 */
final class AsMoney implements Castable
{
    /**
     * @param  array<array-key, mixed>  $arguments
     */
    public static function castUsing(array $arguments): MoneyCast
    {
        $mode = $arguments[0] ?? null;
        $value = $arguments[1] ?? null;

        if ($mode === null) {
            return new MoneyCast(MoneyCast::MODE_COLUMN);
        }

        if (! in_array($mode, [MoneyCast::MODE_COLUMN, MoneyCast::MODE_FIXED, MoneyCast::MODE_CONFIG, MoneyCast::MODE_ATTRIBUTE], true)
            || ! is_string($value) || $value === '') {
            throw InvalidMoneyConfiguration::invalid('AsMoney', 'use AsMoney::class, currencyColumn(), fixedCurrency(), configCurrency() or attributeCurrency()');
        }

        return new MoneyCast($mode, $value);
    }

    /** Amount + a named currency column, possibly shared with other money attributes. */
    public static function currencyColumn(string $column): string
    {
        return self::class.':'.MoneyCast::MODE_COLUMN.','.$column;
    }

    /** Amount column only; the currency is always this one. */
    public static function fixedCurrency(Currency|string $currency): string
    {
        $code = $currency instanceof Currency ? $currency->code : strtoupper(trim($currency));

        return self::class.':'.MoneyCast::MODE_FIXED.','.$code;
    }

    /** Amount column only; the currency is read from a config key at read time. */
    public static function configCurrency(string $configKey): string
    {
        return self::class.':'.MoneyCast::MODE_CONFIG.','.$configKey;
    }

    /** Amount column only; the currency is another (possibly accessor-derived) attribute. */
    public static function attributeCurrency(string $attribute): string
    {
        return self::class.':'.MoneyCast::MODE_ATTRIBUTE.','.$attribute;
    }
}
