<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Testing;

use BadMethodCallException;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Assert;
use RoundingMode;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Enums\EcbFeed;
use RoundlyConsulting\Money\Exchange\Conversion;
use RoundlyConsulting\Money\Exchange\Converter;
use RoundlyConsulting\Money\Exchange\ExchangeManager;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Exchange\Providers\ArrayExchangeRateProvider;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Ratio;
use RoundlyConsulting\Money\Support\MoneyConfig;

/**
 * What `Exchange::fake(['EUR/USD' => '1.0854'])` installs: static rates for every driver, no
 * HTTP, no cache; `rates()` records refreshes, stores and prunes instead of running them.
 * `source()` and `extend()` keep the real manager's behaviour. A subtype of the manager, so
 * an injected `ExchangeManager` receives the fake too.
 */
final class FakeExchangeRates extends ExchangeManager
{
    private readonly ArrayExchangeRateProvider $static;

    private readonly FakeRateStore $store;

    /** @var list<string> "FROM/TO" */
    private array $requested = [];

    /**
     * @param  array<array-key, mixed>  $rates  `['EUR/USD' => '1.0854']` or base-keyed
     * @param  string|null  $pivot  omitted: the configured `money.exchange.pivot`, like the real
     *                              drivers; `null`: no triangulation
     */
    public function __construct(Container $container, array $rates = [], ?string $pivot = null)
    {
        parent::__construct($container);

        $this->static = new ArrayExchangeRateProvider($rates, func_num_args() < 3 ? MoneyConfig::pivot() : $pivot, 'fake');
        $this->store = new FakeRateStore($container, $this);
    }

    public function rate(Currency $from, Currency $to, ?CarbonInterface $on = null): ExchangeRate
    {
        $this->requested[] = $from->code.'/'.$to->code;

        return $this->static->rate($from, $to, $on);
    }

    /**
     * Every driver answers from the static rates.
     *
     * @param  string|null  $driver
     */
    public function driver($driver = null): self
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

    public function rates(): FakeRateStore
    {
        return $this->store;
    }

    /**
     * The real manager forwards unknown calls to the driver — which is the fake itself here.
     *
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     */
    public function __call($method, $parameters): never
    {
        throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', self::class, $method));
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

    public function assertRefreshed(?string $source = null, ?EcbFeed $feed = null): void
    {
        $this->store->assertRefreshed($source, $feed);
    }

    public function assertNothingRefreshed(): void
    {
        $this->store->assertNothingRefreshed();
    }

    public function assertRefreshQueued(?string $source = null, ?EcbFeed $feed = null): void
    {
        $this->store->assertRefreshQueued($source, $feed);
    }

    public function assertNothingQueued(): void
    {
        $this->store->assertNothingQueued();
    }

    public function assertStored(Currency|string $from, Currency|string $to, Ratio|string|null $rate = null, ?string $source = null): void
    {
        $this->store->assertStored($from, $to, $rate, $source);
    }

    public function assertNothingStored(): void
    {
        $this->store->assertNothingStored();
    }

    public function assertPruned(?CarbonInterface $before = null): void
    {
        $this->store->assertPruned($before);
    }

    public function assertNothingPruned(): void
    {
        $this->store->assertNothingPruned();
    }
}
