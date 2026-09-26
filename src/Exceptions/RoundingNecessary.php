<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class RoundingNecessary extends MoneyException
{
    public static function for(string $value, int $scale): self
    {
        return new self("[{$value}] has more precision than {$scale} decimal place(s); pass a rounding mode to round it explicitly.");
    }
}
