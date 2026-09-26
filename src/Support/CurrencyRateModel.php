<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Models\CurrencyRate;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * The one place the (possibly host-swapped) rate model is resolved.
 */
final class CurrencyRateModel
{
    /** @return class-string<CurrencyRate> */
    public static function class(): string
    {
        $class = ModelResolver::for('money.exchange.providers.database.model', CurrencyRate::class);

        if (! is_a($class, CurrencyRate::class, true)) {
            throw InvalidMoneyConfiguration::invalid('money.exchange.providers.database.model', "{$class} must extend ".CurrencyRate::class);
        }

        return $class;
    }

    /** @return Builder<CurrencyRate> */
    public static function query(): Builder
    {
        return self::class()::query();
    }
}
