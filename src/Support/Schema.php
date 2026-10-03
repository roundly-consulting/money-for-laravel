<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Support;

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
        return MoneyConfig::integer('money.schema.precision', 38, min: 19, max: IntegerString::MAX_DIGITS);
    }

    /** varchar length of currency columns (3..10). */
    public static function currencyLength(): int
    {
        return MoneyConfig::integer('money.schema.currency_length', 3, min: 3, max: 10);
    }
}
