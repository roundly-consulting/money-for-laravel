<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class AmountOverflow extends MoneyException
{
    public static function digits(string $operation, int $max = 65): self
    {
        return new self("The result of [{$operation}] has more than {$max} significant digits of minor units.");
    }

    public static function int64(string $minor): self
    {
        return new self("The amount [{$minor}] does not fit a 64-bit integer; use minor() (a string) instead of minorInt().");
    }
}
