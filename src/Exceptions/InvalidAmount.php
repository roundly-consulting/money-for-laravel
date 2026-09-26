<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class InvalidAmount extends MoneyException
{
    public static function notDecimal(string $value): self
    {
        return new self("[{$value}] is not a plain decimal number (expected e.g. 10.50, no grouping, no exponent).");
    }

    public static function notInteger(string $value): self
    {
        return new self("[{$value}] is not an integer amount of minor units (expected ASCII digits with an optional leading minus).");
    }

    public static function tooLong(int $length, int $max): self
    {
        return new self("The amount has {$length} characters/digits; at most {$max} are accepted.");
    }

    public static function float(string $reason): self
    {
        return new self("The floating-point amount cannot be converted exactly: {$reason}. Send amounts as strings.");
    }

    public static function ambiguousCurrency(string $symbol): self
    {
        return new self("The currency symbol [{$symbol}] is used by several currencies; pass the currency explicitly.");
    }

    public static function scale(int $scale, int $max): self
    {
        return new self("A scale of {$scale} is outside the supported range 0..{$max}.");
    }

    public static function unparsable(string $input, string $reason): self
    {
        return new self("[{$input}] cannot be parsed as money: {$reason}.");
    }

    public static function inconsistent(string $minor, string $decimal): self
    {
        return new self("The payload's minor amount [{$minor}] does not match its decimal amount [{$decimal}].");
    }

    public static function missingKey(string $key): self
    {
        return new self("The money payload is missing the [{$key}] key.");
    }
}
