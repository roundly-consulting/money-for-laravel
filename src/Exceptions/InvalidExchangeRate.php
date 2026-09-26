<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class InvalidExchangeRate extends MoneyException
{
    public static function nonPositive(string $rate): self
    {
        return new self("An exchange rate must be positive, [{$rate}] given.");
    }

    public static function sameCurrency(string $code): self
    {
        return new self("An exchange rate from [{$code}] to itself can only be 1.");
    }

    public static function notStorable(string $rate): self
    {
        return new self("The rate [{$rate}] has no exact decimal of at most 40 characters and cannot be stored without rounding.");
    }
}
