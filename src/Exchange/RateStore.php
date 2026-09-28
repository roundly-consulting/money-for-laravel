<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exchange;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\Money\Actions\PruneExchangeRatesAction;
use RoundlyConsulting\Money\Actions\RefreshExchangeRatesAction;
use RoundlyConsulting\Money\Actions\StoreExchangeRatesAction;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\DataTransferObjects\PruneExchangeRatesData;
use RoundlyConsulting\Money\DataTransferObjects\RefreshExchangeRatesData;
use RoundlyConsulting\Money\DataTransferObjects\RefreshResult;
use RoundlyConsulting\Money\Enums\EcbFeed;
use RoundlyConsulting\Money\Jobs\RefreshExchangeRates;
use RoundlyConsulting\Money\Ratio;

/**
 * `Exchange::rates()` — the stored rates table: refresh it from a source, write rates into
 * it, add manual rates, prune it. Every method resolves its action from the container, so
 * host overrides apply; not final only so `Exchange::fake()` can record these calls.
 */
class RateStore
{
    public function __construct(protected readonly Container $container) {}

    /** Fetch a source into the table now; fires ExchangeRatesRefreshed (or …RefreshFailed and rethrows). */
    public function refresh(string $source = 'ecb', EcbFeed $feed = EcbFeed::Daily, ?CarbonInterface $from = null, ?CarbonInterface $to = null): RefreshResult
    {
        return $this->container->make(RefreshExchangeRatesAction::class)->execute(
            new RefreshExchangeRatesData($source, $feed, self::date($from), self::date($to)),
        );
    }

    /** Queue the same refresh (unique per source + feed for ten minutes). */
    public function refreshLater(string $source = 'ecb', EcbFeed $feed = EcbFeed::Daily, ?CarbonInterface $from = null, ?CarbonInterface $to = null): void
    {
        dispatch(new RefreshExchangeRates($source, $feed->value, $from?->toDateString(), $to?->toDateString()));
    }

    /** Upsert rates; a stored `manual` row is only ever overwritten by another manual rate. */
    public function store(ExchangeRate ...$rates): RefreshResult
    {
        return $this->container->make(StoreExchangeRatesAction::class)->execute($rates);
    }

    /** One `manual` rate (`1 from = rate × to`), effective today in `money.exchange.timezone` unless dated. */
    public function manual(Currency|string $from, Currency|string $to, Ratio|string $rate, ?CarbonInterface $on = null): RefreshResult
    {
        return $this->store(new ExchangeRate(
            $from instanceof Currency ? $from : Currency::of($from),
            $to instanceof Currency ? $to : Currency::of($to),
            $rate instanceof Ratio ? $rate : Ratio::of($rate),
            $on ?? CarbonImmutable::now((string) config('money.exchange.timezone')),
            'manual',
        ));
    }

    /** Delete (or with `pretend`, count) rows effective before a date; `manual` rows stay unless included. */
    public function prune(CarbonInterface $before, bool $includeManual = false, bool $pretend = false): int
    {
        return $this->container->make(PruneExchangeRatesAction::class)->execute(
            new PruneExchangeRatesData(CarbonImmutable::instance($before), $includeManual, $pretend),
        );
    }

    private static function date(?CarbonInterface $date): ?CarbonImmutable
    {
        return $date === null ? null : CarbonImmutable::instance($date);
    }
}
