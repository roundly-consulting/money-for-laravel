<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class UnknownCurrency extends MoneyException
{
    public static function code(string $code): self
    {
        return new self("The currency [{$code}] is not registered. Register custom currencies with Currencies::register() or money.currencies.custom.");
    }
}
