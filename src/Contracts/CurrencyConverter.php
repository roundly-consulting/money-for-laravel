<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Contracts;

use Carbon\CarbonInterface;
use RoundingMode;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exchange\Conversion;
use RoundlyConsulting\Money\Money;

interface CurrencyConverter
{
    public function convert(Money $money, Currency|string $to, ?CarbonInterface $on = null, ?RoundingMode $rounding = null): Money;

    public function convertWithRate(Money $money, Currency|string $to, ?CarbonInterface $on = null, ?RoundingMode $rounding = null): Conversion;
}
