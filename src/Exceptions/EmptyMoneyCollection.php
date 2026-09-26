<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class EmptyMoneyCollection extends MoneyException
{
    public static function for(string $operation): self
    {
        return new self("Cannot compute [{$operation}] of an empty collection; pass a currency for the empty case.");
    }
}
