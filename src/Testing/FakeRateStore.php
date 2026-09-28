<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Testing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\DataTransferObjects\PruneExchangeRatesData;
use RoundlyConsulting\Money\DataTransferObjects\RefreshExchangeRatesData;
use RoundlyConsulting\Money\DataTransferObjects\RefreshResult;
use RoundlyConsulting\Money\Enums\EcbFeed;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Exchange\RateStore;
use RoundlyConsulting\Money\Ratio;

/**
 * What `Exchange::rates()` returns under `Exchange::fake()`: records every call and never
 * fetches, writes, queues or deletes anything. The asserts are also on the fake itself.
 */
final class FakeRateStore extends RateStore
{
    /** @var list<RefreshExchangeRatesData> */
    private array $refreshed = [];

    /** @var list<RefreshExchangeRatesData> */
    private array $queued = [];

    /** @var list<ExchangeRate> */
    private array $stored = [];

    /** @var list<PruneExchangeRatesData> */
    private array $pruned = [];

    public function refresh(string $source = 'ecb', EcbFeed $feed = EcbFeed::Daily, ?CarbonInterface $from = null, ?CarbonInterface $to = null): RefreshResult
    {
        $this->refreshed[] = self::refreshData($source, $feed, $from, $to);

        return new RefreshResult($source, 0, 0, 0, null);
    }

    public function refreshLater(string $source = 'ecb', EcbFeed $feed = EcbFeed::Daily, ?CarbonInterface $from = null, ?CarbonInterface $to = null): void
    {
        $this->queued[] = self::refreshData($source, $feed, $from, $to);
    }

    public function store(ExchangeRate ...$rates): RefreshResult
    {
        $latest = null;

        foreach ($rates as $rate) {
            $this->stored[] = $rate;
            $latest = $latest === null || $rate->date->greaterThan($latest) ? $rate->date : $latest;
        }

        return new RefreshResult($rates === [] ? 'manual' : $rates[0]->source, count($rates), 0, 0, $latest);
    }

    public function prune(CarbonInterface $before, bool $includeManual = false, bool $pretend = false): int
    {
        $this->pruned[] = new PruneExchangeRatesData(CarbonImmutable::instance($before), $includeManual, $pretend);

        return 0;
    }

    public function assertRefreshed(?string $source = null, ?EcbFeed $feed = null): void
    {
        Assert::assertNotSame([], self::matchingRefreshes($this->refreshed, $source, $feed), self::refreshMessage('refreshed', $source, $feed));
    }

    public function assertNothingRefreshed(): void
    {
        Assert::assertSame([], $this->refreshed, count($this->refreshed).' exchange-rate refresh(es) ran.');
    }

    public function assertRefreshQueued(?string $source = null, ?EcbFeed $feed = null): void
    {
        Assert::assertNotSame([], self::matchingRefreshes($this->queued, $source, $feed), self::refreshMessage('queued', $source, $feed));
    }

    public function assertNothingQueued(): void
    {
        Assert::assertSame([], $this->queued, count($this->queued).' exchange-rate refresh(es) were queued.');
    }

    /** A rate for the pair was stored (by `store()` or `manual()`), optionally with this exact rate and source. */
    public function assertStored(Currency|string $from, Currency|string $to, Ratio|string|null $rate = null, ?string $source = null): void
    {
        $from = $from instanceof Currency ? $from->code : strtoupper($from);
        $to = $to instanceof Currency ? $to->code : strtoupper($to);
        $expected = $rate === null || $rate instanceof Ratio ? $rate : Ratio::of($rate);

        $matches = array_filter($this->stored, static fn (ExchangeRate $stored): bool => $stored->from->code === $from
            && $stored->to->code === $to
            && ($expected === null || $stored->rate->equals($expected))
            && ($source === null || $stored->source === $source));

        Assert::assertNotSame([], $matches, "No exchange rate [{$from}/{$to}] matching the expectation was stored.");
    }

    public function assertNothingStored(): void
    {
        Assert::assertSame([], $this->stored, count($this->stored).' exchange rate(s) were stored.');
    }

    /** A prune ran, optionally with exactly this cut-off day. */
    public function assertPruned(?CarbonInterface $before = null): void
    {
        $day = $before?->format('Y-m-d');
        $matches = array_filter($this->pruned, static fn (PruneExchangeRatesData $data): bool => $day === null || $data->before->format('Y-m-d') === $day);

        Assert::assertNotSame([], $matches, $day === null ? 'Exchange rates were not pruned.' : "Exchange rates were not pruned before [{$day}].");
    }

    public function assertNothingPruned(): void
    {
        Assert::assertSame([], $this->pruned, count($this->pruned).' exchange-rate prune(s) ran.');
    }

    private static function refreshData(string $source, EcbFeed $feed, ?CarbonInterface $from, ?CarbonInterface $to): RefreshExchangeRatesData
    {
        return new RefreshExchangeRatesData(
            $source,
            $feed,
            $from === null ? null : CarbonImmutable::instance($from),
            $to === null ? null : CarbonImmutable::instance($to),
        );
    }

    /**
     * @param  list<RefreshExchangeRatesData>  $calls
     * @return array<int, RefreshExchangeRatesData>
     */
    private static function matchingRefreshes(array $calls, ?string $source, ?EcbFeed $feed): array
    {
        return array_filter($calls, static fn (RefreshExchangeRatesData $data): bool => ($source === null || $data->source === $source)
            && ($feed === null || $data->feed === $feed));
    }

    private static function refreshMessage(string $verb, ?string $source, ?EcbFeed $feed): string
    {
        return 'No exchange-rate refresh was '.$verb
            .($source === null ? '' : " from [{$source}]")
            .($feed === null ? '' : " with the [{$feed->value}] feed")
            .'.';
    }
}
