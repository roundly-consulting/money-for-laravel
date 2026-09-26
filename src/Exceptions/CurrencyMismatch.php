<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

use RoundlyConsulting\Money\Currency;

final class CurrencyMismatch extends MoneyException
{
    public static function between(Currency $a, Currency $b): self
    {
        return new self("Cannot combine [{$a->code}] (exponent {$a->exponent}) with [{$b->code}] (exponent {$b->exponent}); convert explicitly first.");
    }

    public static function column(string $column, string $stored, string $given): self
    {
        return new self("The currency column [{$column}] already holds [{$stored}]; refusing to re-denominate it to [{$given}]. Set the currency column first to change it on purpose.");
    }
}
