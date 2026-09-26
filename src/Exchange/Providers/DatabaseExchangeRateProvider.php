<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exchange\Providers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RoundlyConsulting\Money\Contracts\ExchangeRateProvider;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\ExchangeRateUnavailable;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Models\CurrencyRate;
use RoundlyConsulting\Money\Ratio;
use RoundlyConsulting\Money\Support\CurrencyRateModel;

/**
 * Rates from the `money_exchange_rates` table: the newest row effective on or before the
 * requested date (taken in money.exchange.timezone) for the direct pair, else the inverse
 * pair, else triangulated through the pivot. A newest row older than max_age_days is
 * stale, and a future date has no rate yet.
 */
final class DatabaseExchangeRateProvider implements ExchangeRateProvider
{
    public function __construct(
        private readonly int $maxAgeDays = 7,
        private readonly string $timezone = 'Europe/Berlin',
        private readonly ?string $pivot = 'EUR',
    ) {}

    public function rate(Currency $from, Currency $to, ?CarbonInterface $on = null): ExchangeRate
    {
        $date = $this->dateFor($on);

        if ($from->equals($to)) {
            return new ExchangeRate($from, $to, Ratio::one(), $date, 'database');
        }

        $rate = $this->pair($from, $to, $date);

        if ($rate === null && $this->pivot !== null && ! in_array($this->pivot, [$from->code, $to->code], true)) {
            $pivot = Currency::of($this->pivot);
            $first = $this->pair($from, $pivot, $date);
            $second = $first === null ? null : $this->pair($pivot, $to, $date);

            $rate = $first !== null && $second !== null ? $first->through($second) : null;
        }

        return $rate ?? throw ExchangeRateUnavailable::between($from, $to, $on);
    }

    /**
     * The newest stored row for a pair effective on or before `$date` (the swap seam: the
     * row is an instance of the configured model).
     */
    public function row(Currency|string $base, Currency|string $quote, CarbonInterface $date): ?CurrencyRate
    {
        return CurrencyRateModel::query()
            ->pair($base, $quote)
            ->effectiveOnOrBefore($date)
            ->orderByDesc('effective_date')
            ->first();
    }

    private function pair(Currency $from, Currency $to, CarbonImmutable $date): ?ExchangeRate
    {
        $direct = $this->row($from, $to, $date);
        $rate = $direct?->toExchangeRate() ?? $this->row($to, $from, $date)?->toExchangeRate()->invert();

        // Calendar dates, not instants: a row's date is midnight in the app timezone while
        // `$date` is midnight in the exchange timezone, which shifted the cut-off by a day.
        if ($rate !== null && $rate->date->toDateString() < $date->subDays($this->maxAgeDays)->toDateString()) {
            throw ExchangeRateUnavailable::stale($rate, $this->maxAgeDays);
        }

        return $rate;
    }

    private function dateFor(?CarbonInterface $on): CarbonImmutable
    {
        $today = CarbonImmutable::now($this->timezone)->startOfDay();

        if ($on === null) {
            return $today;
        }

        $date = CarbonImmutable::instance($on)->setTimezone($this->timezone)->startOfDay();

        if ($date->greaterThan($today)) {
            throw ExchangeRateUnavailable::futureDate($date);
        }

        return $date;
    }
}
