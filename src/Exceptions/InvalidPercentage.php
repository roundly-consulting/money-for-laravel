<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class InvalidPercentage extends MoneyException
{
    public static function negative(string $value): self
    {
        return new self("A percentage cannot be negative, [{$value}] given.");
    }

    public static function precision(string $value, int $max): self
    {
        return new self("A percentage has at most {$max} fraction digits, [{$value}] given.");
    }
}
