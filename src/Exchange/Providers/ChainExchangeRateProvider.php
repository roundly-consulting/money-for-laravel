<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exchange\Providers;

use Carbon\CarbonInterface;
use RoundlyConsulting\Money\Contracts\ExchangeRateProvider;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\ExchangeRateFetchFailed;
use RoundlyConsulting\Money\Exceptions\ExchangeRateUnavailable;
use RoundlyConsulting\Money\Exchange\ExchangeRate;

/**
 * Tries providers in order; the first success wins. Only "no rate" and "fetch failed"
 * fall through — anything else (a missing table, a misconfiguration) propagates.
 */
final class ChainExchangeRateProvider implements ExchangeRateProvider
{
    /**
     * @param  array<string, ExchangeRateProvider>  $providers  keyed by driver name
     */
    public function __construct(private readonly array $providers) {}

    public function rate(Currency $from, Currency $to, ?CarbonInterface $on = null): ExchangeRate
    {
        $reasons = [];

        foreach ($this->providers as $name => $provider) {
            try {
                return $provider->rate($from, $to, $on);
            } catch (ExchangeRateUnavailable|ExchangeRateFetchFailed $exception) {
                $reasons[] = $name.': '.$exception->getMessage();
            }
        }

        throw ExchangeRateUnavailable::chain($reasons);
    }
}
