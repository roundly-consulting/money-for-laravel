<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Contracts;

use Carbon\CarbonInterface;
use RoundlyConsulting\Money\Exchange\ExchangeRate;

/** Something that can be fetched into the rates table by `money:rates:refresh`. */
interface ExchangeRateSource
{
    public function name(): string;

    /**
     * @return iterable<ExchangeRate>
     */
    public function fetch(CarbonInterface $from, CarbonInterface $to): iterable;
}
