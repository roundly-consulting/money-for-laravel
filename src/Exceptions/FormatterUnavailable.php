<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class FormatterUnavailable extends MoneyException
{
    public static function intl(): self
    {
        return new self('The "intl" formatting driver needs ext-intl, which is not loaded; install it or set the driver to "auto" or "decimal".');
    }
}
