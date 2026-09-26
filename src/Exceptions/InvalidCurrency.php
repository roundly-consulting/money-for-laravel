<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class InvalidCurrency extends MoneyException
{
    public static function code(string $code, bool $iso): self
    {
        $rule = $iso ? 'three upper-case letters' : '2–10 characters: an upper-case letter followed by upper-case letters or digits';

        return new self("[{$code}] is not a valid currency code ({$rule}).");
    }

    public static function exponent(string $code, int $exponent, int $max): self
    {
        return new self("The currency [{$code}] has exponent {$exponent}; it must be between 0 and {$max}.");
    }

    public static function numeric(string $code, ?int $numeric): self
    {
        $given = $numeric === null ? 'none' : (string) $numeric;

        return new self("The ISO currency [{$code}] needs a numeric code between 1 and 999, {$given} given.");
    }

    public static function name(string $code): self
    {
        return new self("The currency [{$code}] needs a non-empty name.");
    }

    public static function symbol(string $code, string $symbol): self
    {
        return new self("The currency [{$code}] symbol [{$symbol}] is longer than 8 characters.");
    }

    public static function tooLongForSchema(string $code, int $length): self
    {
        return new self("The currency code [{$code}] is longer than money.schema.currency_length ({$length}); currency columns would truncate or reject it.");
    }
}
