<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exchange\Providers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RoundlyConsulting\Money\Contracts\ExchangeRateProvider;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\ExchangeRateUnavailable;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Ratio;

/**
 * Static rates, from config (`['EUR' => ['USD' => '1.0854']]`) or code
 * (`new ArrayExchangeRateProvider(['EUR/USD' => '1.0854'])`). A direct pair wins, then the
 * exact inverse, then triangulation through the pivot. Date-agnostic by design: `$on` is
 * recorded on the returned rate but not used for lookup.
 */
final class ArrayExchangeRateProvider implements ExchangeRateProvider
{
    /** @var array<string, array<string, Ratio>> base code → quote code → rate */
    private array $rates = [];

    /**
     * @param  array<array-key, mixed>  $rates
     */
    public function __construct(array $rates = [], private readonly ?string $pivot = 'EUR', private readonly string $source = 'config')
    {
        foreach ($rates as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $quote => $rate) {
                    $this->put((string) $key, (string) $quote, $rate);
                }

                continue;
            }

            $pair = explode('/', (string) $key);

            if (count($pair) !== 2) {
                throw InvalidMoneyConfiguration::invalid('money.exchange.providers.config.rates', "[{$key}] is not a BASE/QUOTE pair");
            }

            $this->put($pair[0], $pair[1], $value);
        }
    }

    public function rate(Currency $from, Currency $to, ?CarbonInterface $on = null): ExchangeRate
    {
        $date = $on ?? CarbonImmutable::now();

        if ($from->equals($to)) {
            return new ExchangeRate($from, $to, Ratio::one(), $date, $this->source);
        }

        $ratio = $this->ratio($from->code, $to->code);

        if ($ratio === null && $this->pivot !== null && ! in_array($this->pivot, [$from->code, $to->code], true)) {
            $first = $this->ratio($from->code, $this->pivot);
            $second = $this->ratio($this->pivot, $to->code);
            $ratio = $first !== null && $second !== null ? $first->multiply($second) : null;
        }

        if ($ratio === null) {
            throw ExchangeRateUnavailable::between($from, $to, $on);
        }

        return new ExchangeRate($from, $to, $ratio, $date, $this->source);
    }

    private function ratio(string $from, string $to): ?Ratio
    {
        if (isset($this->rates[$from][$to])) {
            return $this->rates[$from][$to];
        }

        return isset($this->rates[$to][$from]) ? $this->rates[$to][$from]->invert() : null;
    }

    private function put(string $base, string $quote, mixed $rate): void
    {
        if (! is_string($rate) && ! is_int($rate)) {
            throw InvalidMoneyConfiguration::invalid('money.exchange.providers.config.rates', "the {$base}/{$quote} rate must be a decimal string");
        }

        $ratio = Ratio::of($rate);

        if ($ratio->isZero() || $ratio->isNegative()) {
            throw InvalidMoneyConfiguration::invalid('money.exchange.providers.config.rates', "the {$base}/{$quote} rate must be positive");
        }

        $this->rates[strtoupper(trim($base))][strtoupper(trim($quote))] = $ratio;
    }
}
