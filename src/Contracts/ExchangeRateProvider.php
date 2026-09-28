<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Contracts;

use Carbon\CarbonInterface;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\ExchangeRateFetchFailed;
use RoundlyConsulting\Money\Exceptions\ExchangeRateUnavailable;
use RoundlyConsulting\Money\Exchange\ExchangeRate;

interface ExchangeRateProvider
{
    /**
     * The rate to convert `$from` → `$to` effective on `$on` (null = latest). `$on` is a
     * calendar day: its `Y-m-d` in its own timezone, never shifted into another one.
     *
     * @throws ExchangeRateUnavailable when no rate is known
     * @throws ExchangeRateFetchFailed when a remote source cannot be read
     */
    public function rate(Currency $from, Currency $to, ?CarbonInterface $on = null): ExchangeRate;
}
