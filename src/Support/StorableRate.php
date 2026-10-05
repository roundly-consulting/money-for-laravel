<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Support;

use RoundlyConsulting\Money\Exceptions\InvalidExchangeRate;
use RoundlyConsulting\Money\Exchange\ExchangeRate;

/**
 * The rates table stores an exact decimal of at most 40 characters; a rate without one
 * (e.g. 1/3) is refused, never rounded. Shared by the store action and the fake, so a test
 * refuses what production refuses.
 *
 * @internal
 */
final class StorableRate
{
    public static function decimal(ExchangeRate $rate): string
    {
        $decimal = $rate->rate->toExactDecimal(38);

        if ($decimal === null || strlen($decimal) > 40) {
            throw InvalidExchangeRate::notStorable((string) $rate->rate);
        }

        return $decimal;
    }
}
