<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class InvalidAllocation extends MoneyException
{
    public static function emptyRatios(): self
    {
        return new self('Allocation needs at least one ratio.');
    }

    public static function negativeRatio(string $ratio): self
    {
        return new self("Allocation ratios cannot be negative, [{$ratio}] given.");
    }

    public static function zeroTotal(): self
    {
        return new self('Allocation ratios must sum to more than zero.');
    }

    public static function parts(int $parts, int $max): self
    {
        return new self("Cannot split into {$parts} parts; between 1 and {$max} are allowed.");
    }
}
