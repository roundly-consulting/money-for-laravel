<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exchange\Providers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Exception;
use Illuminate\Contracts\Cache\Repository;
use RoundlyConsulting\Money\Contracts\ExchangeRateProvider;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Exchange\RateCacheGeneration;
use RoundlyConsulting\Money\Ratio;

/**
 * Caches successful lookups as **scalars** (numerator, denominator, date and its timezone,
 * source) — never a serialized object, so hosts that restrict cache unserialization still
 * read them back and the entries survive refactors. Failures are never cached. Keys carry
 * the {@see RateCacheGeneration}, so every write to the stored rates invalidates them all.
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
        $generation = (new RateCacheGeneration($this->cache, $this->prefix))->current();
        $key = "{$this->prefix}:{$generation}:{$this->driver}:{$from->code}:{$to->code}:{$date}";

        $cached = $this->cache->get($key);

        if (is_array($cached) && is_string($cached['num'] ?? null) && is_string($cached['den'] ?? null)
            && is_string($cached['source'] ?? null) && ($date = self::cachedDate($cached)) !== null) {
            return new ExchangeRate($from, $to, Ratio::fromIntegers($cached['num'], $cached['den']), $date, $cached['source']);
        }

        $rate = $this->inner->rate($from, $to, $on);

        // The date's timezone travels with it, so a hit is dated exactly like the fresh rate.
        $this->cache->put($key, [
            'num' => $rate->rate->numerator(),
            'den' => $rate->rate->denominator(),
            'date' => $rate->date->toDateString(),
            'tz' => $rate->date->getTimezone()->getName(),
            'source' => $rate->source,
        ], $this->ttl);

        return $rate;
    }

    /**
     * @param  array<mixed>  $cached
     */
    private static function cachedDate(array $cached): ?CarbonImmutable
    {
        $date = $cached['date'] ?? null;
        $timezone = $cached['tz'] ?? null;

        if (! is_string($date) || ! is_string($timezone) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            return null;
        }

        try {
            $zone = new DateTimeZone($timezone);
        } catch (Exception) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $zone);

        return $parsed instanceof CarbonImmutable ? $parsed : null;
    }
}
