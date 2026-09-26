<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class InvalidRatio extends MoneyException
{
    public static function zeroDenominator(): self
    {
        return new self('A ratio cannot have a zero denominator (inverting zero is undefined).');
    }

    public static function tooLarge(int $max): self
    {
        return new self("The ratio's numerator or denominator exceeds {$max} digits after reduction.");
    }
}
