<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class InvalidDiscount extends MoneyException
{
    public static function negative(string $amount): self
    {
        return new self("A discount cannot be negative, [{$amount}] given.");
    }

    public static function percentOutOfRange(string $percent): self
    {
        return new self("A percentage discount must be between 0 and 100, [{$percent}] given.");
    }

    public static function negativeCap(string $cap): self
    {
        return new self("A discount cap cannot be negative, [{$cap}] given.");
    }

    public static function exceedsLines(string $discount, string $lines): self
    {
        return new self("The discount [{$discount}] is larger than the lines it is spread over [{$lines}].");
    }

    public static function negativeLine(string $line): self
    {
        return new self("Discounts can only be spread over non-negative lines, [{$line}] given.");
    }
}
