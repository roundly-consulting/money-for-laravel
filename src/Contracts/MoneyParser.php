<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Contracts;

use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Money;

interface MoneyParser
{
    /**
     * Parse localized input ("1 234,50 €", "(1,234.50)", "EUR 10") into exact Money.
     * Excess precision throws RoundingNecessary; nothing is guessed.
     */
    public function parse(string $input, Currency|string|null $currency = null, ?string $locale = null): Money;
}
