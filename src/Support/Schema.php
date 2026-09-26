<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Support;

use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Math\IntegerString;

/**
 * The two schema knobs, validated. Both are read when migrations run and are fixed from
 * then on; the cast and the validation rule read the same keys so they enforce what the
 * columns can actually hold.
 *
 * @internal
 */
final class Schema
{
    /** `P` of the decimal(P, 0) amount columns: 19 (int64 parity) .. 65 (MySQL's maximum). */
    public static function precision(): int
    {
        $precision = config('money.schema.precision', 38);

        if (! is_int($precision) || $precision < 19 || $precision > IntegerString::MAX_DIGITS) {
            throw InvalidMoneyConfiguration::precision(is_int($precision) ? $precision : 0);
        }

        return $precision;
    }

    /** varchar length of currency columns (3..10). */
    public static function currencyLength(): int
    {
        $length = config('money.schema.currency_length', 3);

        if (! is_int($length) || $length < 3 || $length > 10) {
            throw InvalidMoneyConfiguration::invalid('money.schema.currency_length', 'expected an integer between 3 and 10');
        }

        return $length;
    }
}
