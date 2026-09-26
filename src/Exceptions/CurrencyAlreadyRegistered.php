<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class CurrencyAlreadyRegistered extends MoneyException
{
    public static function code(string $code): self
    {
        return new self("The currency [{$code}] is already registered; pass replace: true to redefine a custom currency.");
    }

    public static function iso(string $code): self
    {
        return new self("The ISO currency [{$code}] cannot be redefined: a different exponent would silently corrupt stored amounts.");
    }
}
