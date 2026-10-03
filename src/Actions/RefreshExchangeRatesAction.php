<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Money\DataTransferObjects\RefreshExchangeRatesData;
use RoundlyConsulting\Money\DataTransferObjects\RefreshResult;
use RoundlyConsulting\Money\Enums\EcbFeed;
use RoundlyConsulting\Money\Events\ExchangeRatesRefreshed;
use RoundlyConsulting\Money\Events\ExchangeRatesRefreshFailed;
use RoundlyConsulting\Money\Exchange\ExchangeManager;
use RoundlyConsulting\Money\Exchange\Providers\EcbExchangeRateProvider;
use RoundlyConsulting\Money\Support\MoneyConfig;
use Throwable;

/**
 * Fetches a source (un-cached, via ExchangeManager::source()) into the rates table. Fires
 * ExchangeRatesRefreshed, or ExchangeRatesRefreshFailed and rethrows; a failed refresh
 * never deletes existing rows. Facade form: `Exchange::rates()->refresh('ecb')`.
 */
final readonly class RefreshExchangeRatesAction
{
    public function __construct(
        private ExchangeManager $exchange,
        private StoreExchangeRatesAction $store,
        private Dispatcher $events,
    ) {}

    public function execute(RefreshExchangeRatesData $data): RefreshResult
    {
        try {
            $source = $this->exchange->source($data->source);

            if ($source instanceof EcbExchangeRateProvider) {
                $source = $source->usingFeed($data->feed);
            }

            $to = $data->to ?? CarbonImmutable::now(MoneyConfig::timezone())->startOfDay();
            $from = $data->from ?? match ($data->feed) {
                EcbFeed::Daily => $to->subDays(7),
                EcbFeed::Recent => $to->subDays(EcbExchangeRateProvider::RECENT_DAYS),
                EcbFeed::History => CarbonImmutable::create(1999, 1, 4) ?? $to,
            };

            $stored = $this->store->execute($source->fetch($from, $to));

            $result = new RefreshResult(
                $source->name(),
                $stored->stored,
                $stored->skippedManual,
                $source instanceof EcbExchangeRateProvider ? $source->skippedUnknownCurrencies() : 0,
                $stored->latestDate,
            );
        } catch (Throwable $exception) {
            $this->events->dispatch(new ExchangeRatesRefreshFailed($data->source, $exception->getMessage()));

            throw $exception;
        }

        $this->events->dispatch(new ExchangeRatesRefreshed($result));

        return $result;
    }
}
