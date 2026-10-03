<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exchange;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Manager;
use RoundingMode;
use RoundlyConsulting\Money\Contracts\CurrencyConverter;
use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Contracts\ExchangeRateProvider;
use RoundlyConsulting\Money\Contracts\ExchangeRateSource;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exchange\Providers\ArrayExchangeRateProvider;
use RoundlyConsulting\Money\Exchange\Providers\CachingExchangeRateProvider;
use RoundlyConsulting\Money\Exchange\Providers\ChainExchangeRateProvider;
use RoundlyConsulting\Money\Exchange\Providers\DatabaseExchangeRateProvider;
use RoundlyConsulting\Money\Exchange\Providers\EcbExchangeRateProvider;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Support\MoneyConfig;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * The exchange driver manager (`Exchange` facade): `config`, `database`, `ecb` and `chain`
 * drivers, plus host drivers via `Exchange::extend()`. Every driver but `config` (in
 * memory) and `chain` (composes already-cached drivers) is wrapped in the caching
 * decorator when `money.exchange.cache.enabled`. `rates()` manages the stored rates table.
 *
 * Not final only so `Exchange::fake()` can swap in a subtype that injected managers accept.
 */
class ExchangeManager extends Manager implements ExchangeRateProvider
{
    private const array UNCACHED = ['config', 'chain'];

    public function getDefaultDriver(): string
    {
        return MoneyConfig::exchangeDriver();
    }

    /** A rate from the default driver. */
    public function rate(Currency $from, Currency $to, ?CarbonInterface $on = null): ExchangeRate
    {
        return $this->provider()->rate($from, $to, $on);
    }

    /** A named (or the default) driver, typed. */
    public function provider(?string $name = null): ExchangeRateProvider
    {
        $provider = $this->driver($name);

        if (! $provider instanceof ExchangeRateProvider) {
            throw InvalidMoneyConfiguration::invalid('money.exchange.default', 'the driver must implement ExchangeRateProvider');
        }

        return $provider;
    }

    public function convert(Money $money, Currency|string $to, ?CarbonInterface $on = null, ?RoundingMode $rounding = null): Money
    {
        return $this->container->make(CurrencyConverter::class)->convert($money, $to, $on, $rounding);
    }

    public function convertWithRate(Money $money, Currency|string $to, ?CarbonInterface $on = null, ?RoundingMode $rounding = null): Conversion
    {
        return $this->container->make(CurrencyConverter::class)->convertWithRate($money, $to, $on, $rounding);
    }

    /** The stored rates table: refresh, store, manual rates, prune. */
    public function rates(): RateStore
    {
        return new RateStore($this->container);
    }

    /**
     * The RAW (un-cached) driver as a fetchable source — the refresh pipeline's entry
     * point, since the caching decorator hides the source interface.
     */
    public function source(string $name): ExchangeRateSource
    {
        $driver = isset($this->customCreators[$name]) ? $this->callCustomCreator($name) : $this->createRawDriver($name);

        if (! $driver instanceof ExchangeRateSource) {
            throw InvalidMoneyConfiguration::notASource($name);
        }

        return $driver;
    }

    protected function createConfigDriver(): ExchangeRateProvider
    {
        return new ArrayExchangeRateProvider(MoneyConfig::configRates(), MoneyConfig::pivot());
    }

    protected function createDatabaseDriver(): DatabaseExchangeRateProvider
    {
        return new DatabaseExchangeRateProvider(MoneyConfig::maxAgeDays(), MoneyConfig::timezone(), MoneyConfig::pivot());
    }

    protected function createEcbDriver(): EcbExchangeRateProvider
    {
        return new EcbExchangeRateProvider(
            $this->container->make(CurrencyRegistry::class),
            $this->cacheStore(),
            $this->container->make(HttpFactory::class),
            MoneyConfig::ecbUrls(),
            MoneyConfig::ecbTimeout(),
            MoneyConfig::ecbRetries(),
            MoneyConfig::ecbMaxBytes(),
            MoneyConfig::ecbCacheTtl(),
            MoneyConfig::cachePrefix(),
            MoneyConfig::maxAgeDays(),
            MoneyConfig::timezone(),
        );
    }

    protected function createChainDriver(): ChainExchangeRateProvider
    {
        $providers = [];

        foreach (MoneyConfig::chain() as $name) {
            $providers[$name] = $this->provider($name);
        }

        return new ChainExchangeRateProvider($providers);
    }

    /**
     * @param  string  $driver
     */
    protected function createDriver($driver): mixed
    {
        $provider = parent::createDriver($driver);

        if (in_array($driver, self::UNCACHED, true)
            || ! $provider instanceof ExchangeRateProvider
            || ! Config::boolean('money.exchange.cache.enabled', true)) {
            return $provider;
        }

        return new CachingExchangeRateProvider(
            $provider,
            $driver,
            $this->cacheStore(),
            MoneyConfig::cacheTtl(),
            MoneyConfig::cachePrefix(),
        );
    }

    private function createRawDriver(string $name): mixed
    {
        $method = 'create'.ucfirst($name).'Driver';

        if (! method_exists($this, $method)) {
            throw InvalidMoneyConfiguration::notASource($name);
        }

        return $this->{$method}();
    }

    private function cacheStore(): CacheRepository
    {
        return $this->container->make(CacheFactory::class)->store(MoneyConfig::cacheStore());
    }
}
