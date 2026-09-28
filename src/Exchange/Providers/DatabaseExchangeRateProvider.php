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
use RoundlyConsulting\Money\Support\RateDate;

/**
 * Rates from the `money_exchange_rates` table: the newest row effective on or before the
 * requested date, of the direct or the inverse pair (the direct row wins a tie); when that is
 * missing or stale, triangulated through the pivot. The requested date is its own calendar day
 * ({@see RateDate}); no date means today in money.exchange.timezone. A newest row older than
 * max_age_days is stale — reported only when no fresh route exists — and a day that has not
 * begun yet has no rate.
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

        $stale = null;
        $rate = $this->pair($from, $to, $date, $stale);

        if ($rate === null && $this->pivot !== null && ! in_array($this->pivot, [$from->code, $to->code], true)) {
            $pivot = Currency::of($this->pivot);
            $first = $this->pair($from, $pivot, $date, $stale);
            $second = $first === null ? null : $this->pair($pivot, $to, $date, $stale);

            $rate = $first !== null && $second !== null ? $first->through($second) : null;
        }

        if ($rate !== null) {
            return $rate;
        }

        throw $stale === null ? ExchangeRateUnavailable::between($from, $to, $on) : ExchangeRateUnavailable::stale($stale, $this->maxAgeDays);
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

    /**
     * The fresher of the direct and the inverse row, or null. A stale one is no rate either —
     * so a fresh route through the pivot still wins — but the first stale rate seen is kept
     * for the error message.
     */
    private function pair(Currency $from, Currency $to, CarbonImmutable $date, ?ExchangeRate &$stale): ?ExchangeRate
    {
        $direct = $this->row($from, $to, $date);
        $inverse = $this->row($to, $from, $date);

        $rate = $inverse !== null && ($direct === null || $inverse->effective_date->greaterThan($direct->effective_date))
            ? $inverse->toExchangeRate()->invert()
            : $direct?->toExchangeRate();

        if ($rate === null) {
            return null;
        }

        // A row is a calendar date; date it in the exchange timezone, like the ecb driver.
        $rate = new ExchangeRate($rate->from, $rate->to, $rate->rate, RateDate::of($rate->date, $this->timezone), $rate->source);

        // Calendar dates, not instants: compare the Y-m-d strings.
        if ($rate->date->toDateString() < $date->subDays($this->maxAgeDays)->toDateString()) {
            $stale ??= $rate;

            return null;
        }

        return $rate;
    }

    private function dateFor(?CarbonInterface $on): CarbonImmutable
    {
        if ($on === null) {
            return CarbonImmutable::now($this->timezone)->startOfDay();
        }

        if (RateDate::isFuture($on)) {
            throw ExchangeRateUnavailable::futureDate($on);
        }

        return RateDate::of($on, $this->timezone);
    }
}
