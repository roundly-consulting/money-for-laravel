<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Contracts;

use RoundlyConsulting\Money\Formatting\FormatOptions;
use RoundlyConsulting\Money\Money;

interface MoneyFormatter
{
    /** Locale: explicit → money.formatting.locale → the app locale. */
    public function format(Money $money, ?string $locale = null, ?FormatOptions $options = null): string;
}
