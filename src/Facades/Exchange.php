<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Money\Contracts\ExchangeRateProvider;
use RoundlyConsulting\Money\Exchange\ExchangeManager;
use RoundlyConsulting\Money\Testing\FakeExchangeRates;

/**
 * @method static \RoundlyConsulting\Money\Exchange\ExchangeRate rate(\RoundlyConsulting\Money\Currency $from, \RoundlyConsulting\Money\Currency $to, \Carbon\CarbonInterface|null $on = null)
 * @method static \RoundlyConsulting\Money\Money convert(\RoundlyConsulting\Money\Money $money, \RoundlyConsulting\Money\Currency|string $to, \Carbon\CarbonInterface|null $on = null, \RoundingMode|null $rounding = null)
 * @method static \RoundlyConsulting\Money\Exchange\Conversion convertWithRate(\RoundlyConsulting\Money\Money $money, \RoundlyConsulting\Money\Currency|string $to, \Carbon\CarbonInterface|null $on = null, \RoundingMode|null $rounding = null)
 * @method static \RoundlyConsulting\Money\Contracts\ExchangeRateProvider driver(string|null $driver = null)
 * @method static \RoundlyConsulting\Money\Contracts\ExchangeRateProvider provider(string|null $name = null)
 * @method static \RoundlyConsulting\Money\Contracts\ExchangeRateSource source(string $name)
 * @method static ExchangeManager extend(string $driver, \Closure $callback)
 *
 * @see ExchangeManager
 */
final class Exchange extends Facade
{
    /**
     * Swap in static rates for tests: no HTTP, no cache. `Money::convertTo()` and the
     * converter see the fake immediately.
     *
     * @param  array<array-key, mixed>  $rates  `['EUR/USD' => '1.0854']`
     */
    public static function fake(array $rates = [], ?string $pivot = 'EUR'): FakeExchangeRates
    {
        $fake = new FakeExchangeRates($rates, $pivot);

        self::getFacadeApplication()?->instance(ExchangeRateProvider::class, $fake);
        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return ExchangeManager::class;
    }
}
