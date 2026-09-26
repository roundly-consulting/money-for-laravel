<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exchange;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RoundingMode;
use RoundlyConsulting\Money\Contracts\CurrencyConverter;
use RoundlyConsulting\Money\Contracts\ExchangeRateProvider;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Ratio;
use RoundlyConsulting\Money\Support\RoundingModes;

/**
 * Converts through the bound ExchangeRateProvider. Bound (not a singleton) so a fresh
 * converter — and `Exchange::fake()` — is seen on every resolution.
 */
final class Converter implements CurrencyConverter
{
    public function __construct(private readonly ExchangeRateProvider $provider) {}

    public function convert(Money $money, Currency|string $to, ?CarbonInterface $on = null, ?RoundingMode $rounding = null): Money
    {
        return $this->convertWithRate($money, $to, $on, $rounding)->converted;
    }

    public function convertWithRate(Money $money, Currency|string $to, ?CarbonInterface $on = null, ?RoundingMode $rounding = null): Conversion
    {
        $to = $to instanceof Currency ? $to : Currency::of($to);

        if ($money->currency()->equals($to)) {
            return new Conversion($money, $money, new ExchangeRate($to, $to, Ratio::one(), $on ?? CarbonImmutable::now(), 'identity'));
        }

        $rate = $this->provider->rate($money->currency(), $to, $on);

        return new Conversion($money, $rate->convert($money, $rounding ?? RoundingModes::fromConfig('money.exchange.rounding')), $rate);
    }
}
