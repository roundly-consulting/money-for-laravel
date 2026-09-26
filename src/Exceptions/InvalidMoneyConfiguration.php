<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class InvalidMoneyConfiguration extends MoneyException
{
    public static function rounding(string $key, mixed $value): self
    {
        $given = is_scalar($value) ? (string) $value : get_debug_type($value);

        return new self("[{$key}] must name a rounding mode (e.g. half_even, half_away_from_zero), [{$given}] given.");
    }

    public static function notASource(string $driver): self
    {
        return new self("The exchange driver [{$driver}] cannot be fetched from: it does not implement ExchangeRateSource.");
    }

    public static function precision(int $precision): self
    {
        return new self("[money.schema.precision] must be between 19 and 65, {$precision} given.");
    }

    public static function invalid(string $key, string $reason): self
    {
        return new self("[{$key}] is misconfigured: {$reason}.");
    }

    public static function locale(string $locale): self
    {
        return new self("[{$locale}] is not a valid locale identifier (e.g. en, sk, de_CH, en-US).");
    }
}
