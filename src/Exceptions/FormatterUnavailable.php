<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class FormatterUnavailable extends MoneyException
{
    public static function intl(): self
    {
        return new self('The "intl" formatting driver needs ext-intl, which is not loaded; install it or set the driver to "auto" or "decimal".');
    }

    public static function exact(string $decimal): self
    {
        return new self("ICU could not lay out [{$decimal}] digit-exactly for this locale.");
    }
}
