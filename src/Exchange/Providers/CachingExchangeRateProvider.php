<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exchange\Providers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use RoundlyConsulting\Money\Contracts\ExchangeRateProvider;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Ratio;

/**
 * Caches successful lookups as **scalars** (numerator, denominator, date, source) — never a
 * serialized object, so hosts that restrict cache unserialization still read them back and
 * the entries survive refactors. Failures are never cached.
 */
final class CachingExchangeRateProvider implements ExchangeRateProvider
{
    public function __construct(
        private readonly ExchangeRateProvider $inner,
        private readonly string $driver,
        private readonly Repository $cache,
        private readonly int $ttl,
        private readonly string $prefix,
        private readonly string $timezone,
    ) {}

    public function inner(): ExchangeRateProvider
    {
        return $this->inner;
    }

    public function rate(Currency $from, Currency $to, ?CarbonInterface $on = null): ExchangeRate
    {
        $date = $on === null ? 'latest' : CarbonImmutable::instance($on)->setTimezone($this->timezone)->toDateString();
        $key = "{$this->prefix}:{$this->driver}:{$from->code}:{$to->code}:{$date}";

        $cached = $this->cache->get($key);

        if (is_array($cached) && is_string($cached['num'] ?? null) && is_string($cached['den'] ?? null)
            && is_string($cached['date'] ?? null) && is_string($cached['source'] ?? null)) {
            return new ExchangeRate(
                $from,
                $to,
                Ratio::fromIntegers($cached['num'], $cached['den']),
                CarbonImmutable::createFromFormat('!Y-m-d', $cached['date']) ?: CarbonImmutable::now(),
                $cached['source'],
            );
        }

        $rate = $this->inner->rate($from, $to, $on);

        $this->cache->put($key, [
            'num' => $rate->rate->numerator(),
            'den' => $rate->rate->denominator(),
            'date' => $rate->date->toDateString(),
            'source' => $rate->source,
        ], $this->ttl);

        return $rate;
    }
}
