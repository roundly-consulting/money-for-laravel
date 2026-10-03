<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Models\CurrencyRate;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * The one place the (possibly host-swapped) rate model is resolved.
 */
final class CurrencyRateModel
{
    /** @return class-string<CurrencyRate> */
    public static function class(): string
    {
        try {
            return ModelResolver::for('money.exchange.providers.database.model', CurrencyRate::class);
        } catch (InvalidConfigurationException) {
            // Keep the package's own exception: anything that isn't a CurrencyRate — a
            // typo, a non-model, an unrelated model — is refused, never swapped back.
            $given = config('money.exchange.providers.database.model');

            throw InvalidMoneyConfiguration::invalid(
                'money.exchange.providers.database.model',
                sprintf('must extend %s, [%s] given', CurrencyRate::class, is_string($given) ? $given : get_debug_type($given)),
            );
        }
    }

    /** @return Builder<CurrencyRate> */
    public static function query(): Builder
    {
        return self::class()::query();
    }
}
