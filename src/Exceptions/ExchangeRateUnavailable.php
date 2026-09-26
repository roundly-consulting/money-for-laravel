<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

use Carbon\CarbonInterface;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exchange\ExchangeRate;

final class ExchangeRateUnavailable extends MoneyException
{
    public static function between(Currency $from, Currency $to, ?CarbonInterface $on = null): self
    {
        $date = $on === null ? 'latest' : $on->toDateString();

        return new self("No exchange rate from [{$from->code}] to [{$to->code}] ({$date}).");
    }

    public static function stale(ExchangeRate $rate, int $maxAgeDays): self
    {
        return new self("The newest [{$rate->from->code}→{$rate->to->code}] rate is from {$rate->date->toDateString()}, older than {$maxAgeDays} days (money.exchange.max_age_days).");
    }

    public static function futureDate(CarbonInterface $on): self
    {
        return new self("No exchange rate exists yet for {$on->toDateString()}: the date is in the future.");
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function chain(array $reasons): self
    {
        return new self('Every exchange driver in the chain failed: '.implode('; ', $reasons));
    }
}
