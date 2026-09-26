<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Testing;

use Carbon\CarbonInterface;
use PHPUnit\Framework\Assert;
use RoundingMode;
use RoundlyConsulting\Money\Contracts\ExchangeRateProvider;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exchange\Conversion;
use RoundlyConsulting\Money\Exchange\Converter;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Exchange\Providers\ArrayExchangeRateProvider;
use RoundlyConsulting\Money\Money;

/**
 * What `Exchange::fake(['EUR/USD' => '1.0854'])` installs: static rates, no HTTP, no cache,
 * and assertions on what was asked for.
 */
final class FakeExchangeRates implements ExchangeRateProvider
{
    private readonly ArrayExchangeRateProvider $rates;

    /** @var list<string> "FROM/TO" */
    private array $requested = [];

    /**
     * @param  array<array-key, mixed>  $rates  `['EUR/USD' => '1.0854']` or base-keyed
     */
    public function __construct(array $rates = [], ?string $pivot = 'EUR')
    {
        $this->rates = new ArrayExchangeRateProvider($rates, $pivot, 'fake');
    }

    public function rate(Currency $from, Currency $to, ?CarbonInterface $on = null): ExchangeRate
    {
        $this->requested[] = $from->code.'/'.$to->code;

        return $this->rates->rate($from, $to, $on);
    }

    /** Mirrors the manager, so the swapped facade keeps working. */
    public function driver(?string $driver = null): self
    {
        return $this;
    }

    public function provider(?string $name = null): self
    {
        return $this;
    }

    public function convert(Money $money, Currency|string $to, ?CarbonInterface $on = null, ?RoundingMode $rounding = null): Money
    {
        return (new Converter($this))->convert($money, $to, $on, $rounding);
    }

    public function convertWithRate(Money $money, Currency|string $to, ?CarbonInterface $on = null, ?RoundingMode $rounding = null): Conversion
    {
        return (new Converter($this))->convertWithRate($money, $to, $on, $rounding);
    }

    public function assertRateRequested(string $from, string $to): void
    {
        $pair = strtoupper($from).'/'.strtoupper($to);

        Assert::assertContains($pair, $this->requested, "The exchange rate [{$pair}] was not requested.");
    }

    public function assertNothingRequested(): void
    {
        Assert::assertSame([], $this->requested, 'Exchange rates were requested: '.implode(', ', $this->requested));
    }
}
