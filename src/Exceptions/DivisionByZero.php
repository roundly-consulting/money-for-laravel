<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class DivisionByZero extends MoneyException
{
    public static function create(): self
    {
        return new self('Division by zero.');
    }
}
