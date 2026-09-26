<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exchange\Providers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Contracts\ExchangeRateProvider;
use RoundlyConsulting\Money\Contracts\ExchangeRateSource;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Enums\EcbFeed;
use RoundlyConsulting\Money\Exceptions\ExchangeRateFetchFailed;
use RoundlyConsulting\Money\Exceptions\ExchangeRateUnavailable;
use RoundlyConsulting\Money\Exchange\Ecb\EcbXmlParser;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Ratio;

/**
 * Euro foreign exchange reference rates from the European Central Bank.
 *
 * As a provider: the daily feed for today, the 90-day feed for older dates within 90 days;
 * weekends and holidays take the latest published day on or before the date, and the same
 * max-age staleness rule as the database driver applies (a currency that left the feed goes
 * stale instead of being served forever). Cross rates go through EUR, exactly. The parsed
 * document is cached (scalars only) so one request serves every pair.
 *
 * As a source (`money:rates:refresh ecb`): {@see self::fetch()} always performs the HTTP
 * request and then refreshes the parsed-feed cache — it never trusts a cached document,
 * which may predate the ~16:00 CET publication.
 */
final class EcbExchangeRateProvider implements ExchangeRateProvider, ExchangeRateSource
{
    public const int RECENT_DAYS = 90;

    private int $skippedUnknown = 0;

    /**
     * @param  array<string, string>  $urls  feed value → URL
     */
    public function __construct(
        private readonly CurrencyRegistry $registry,
        private readonly Repository $cache,
        private readonly HttpFactory $http,
        private readonly array $urls,
        private readonly int $timeout = 10,
        private readonly int $retries = 2,
        private readonly int $maxBytes = 33_554_432,
        private readonly int $cacheTtl = 3600,
        private readonly string $cachePrefix = 'money:exchange',
        private readonly int $maxAgeDays = 7,
        private readonly string $timezone = 'Europe/Berlin',
        private readonly EcbFeed $feed = EcbFeed::Daily,
    ) {}

    public function name(): string
    {
        return 'ecb';
    }

    /** The feed {@see self::fetch()} downloads. */
    public function usingFeed(EcbFeed $feed): self
    {
        return new self(
            $this->registry, $this->cache, $this->http, $this->urls, $this->timeout, $this->retries,
            $this->maxBytes, $this->cacheTtl, $this->cachePrefix, $this->maxAgeDays, $this->timezone, $feed,
        );
    }

    /** Rows the last fetch() skipped because the registry does not know their currency. */
    public function skippedUnknownCurrencies(): int
    {
        return $this->skippedUnknown;
    }

    public function rate(Currency $from, Currency $to, ?CarbonInterface $on = null): ExchangeRate
    {
        $today = CarbonImmutable::now($this->timezone)->startOfDay();
        $date = $on === null ? $today : CarbonImmutable::instance($on)->setTimezone($this->timezone)->startOfDay();

        if ($date->greaterThan($today)) {
            throw ExchangeRateUnavailable::futureDate($date);
        }

        if ($from->equals($to)) {
            return new ExchangeRate($from, $to, Ratio::one(), $date, 'ecb');
        }

        $feed = match (true) {
            $date->equalTo($today) => EcbFeed::Daily,
            $date->greaterThanOrEqualTo($today->subDays(self::RECENT_DAYS)) => EcbFeed::Recent,
            default => throw ExchangeRateUnavailable::between($from, $to, $on),
        };

        // The newest published day on or before the date that quotes both currencies — a
        // currency that left the feed is then caught by the staleness rule below.
        foreach ($this->days($feed) as $day => $rates) {
            $euroToFrom = $from->code === 'EUR' ? '1' : ($rates[$from->code] ?? null);
            $euroToTo = $to->code === 'EUR' ? '1' : ($rates[$to->code] ?? null);

            if ($day > $date->toDateString() || $euroToFrom === null || $euroToTo === null) {
                continue;
            }

            // 1 from = (EUR→to) / (EUR→from) to — exact.
            $rate = new ExchangeRate($from, $to, Ratio::of($euroToTo)->divide(Ratio::of($euroToFrom)), CarbonImmutable::createFromFormat('!Y-m-d', $day, $this->timezone) ?: $date, 'ecb');

            if ($rate->date->lessThan($date->subDays($this->maxAgeDays))) {
                throw ExchangeRateUnavailable::stale($rate, $this->maxAgeDays);
            }

            return $rate;
        }

        throw ExchangeRateUnavailable::between($from, $to, $on);
    }

    /**
     * @return list<ExchangeRate> EUR → currency for every published day in [from, to]
     */
    public function fetch(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = $this->download($this->feed);

        $this->cache->put($this->cacheKey($this->feed), $days, $this->cacheTtl);
        $this->skippedUnknown = 0;

        $euro = $this->registry->get('EUR');
        $rates = [];

        foreach ($days as $day => $quotes) {
            if ($day < $from->format('Y-m-d') || $day > $to->format('Y-m-d')) {
                continue;
            }

            $date = CarbonImmutable::createFromFormat('!Y-m-d', $day, $this->timezone) ?: CarbonImmutable::now($this->timezone);

            foreach ($quotes as $code => $rate) {
                $currency = $this->registry->find($code);

                if ($currency === null) {
                    $this->skippedUnknown++;

                    continue;
                }

                $rates[] = new ExchangeRate($euro, $currency, Ratio::of($rate), $date, 'ecb');
            }
        }

        return $rates;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function days(EcbFeed $feed): array
    {
        $cached = $this->cache->get($this->cacheKey($feed));

        if (is_array($cached) && self::isFeed($cached)) {
            return $cached;
        }

        $days = $this->download($feed);

        $this->cache->put($this->cacheKey($feed), $days, $this->cacheTtl);

        return $days;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function download(EcbFeed $feed): array
    {
        $url = $this->urls[$feed->value] ?? '';

        $response = $this->http
            ->timeout($this->timeout)
            ->retry($this->retries + 1, 250, throw: false)
            ->accept('application/xml')
            ->get($url);

        if (! $response->successful()) {
            throw ExchangeRateFetchFailed::http($url, $response->status());
        }

        $body = $response->body();

        if (strlen($body) > $this->maxBytes) {
            throw ExchangeRateFetchFailed::tooLarge(strlen($body), $this->maxBytes);
        }

        return EcbXmlParser::parse($body);
    }

    private function cacheKey(EcbFeed $feed): string
    {
        return "{$this->cachePrefix}:ecb:feed:{$feed->value}";
    }

    /**
     * @param  array<mixed>  $value
     *
     * @phpstan-assert-if-true array<string, array<string, string>> $value
     */
    private static function isFeed(array $value): bool
    {
        foreach ($value as $day => $rates) {
            if (! is_string($day) || ! is_array($rates)) {
                return false;
            }

            foreach ($rates as $code => $rate) {
                if (! is_string($code) || ! is_string($rate)) {
                    return false;
                }
            }
        }

        return $value !== [];
    }
}
