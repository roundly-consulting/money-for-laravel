<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Actions;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use RoundlyConsulting\Money\DataTransferObjects\PruneExchangeRatesData;
use RoundlyConsulting\Money\Exchange\RateCacheGeneration;
use RoundlyConsulting\Money\Support\CurrencyRateModel;

/**
 * Deletes (or, pretending, counts) rates older than a date; `manual` rows stay unless asked.
 * Facade form: `Exchange::rates()->prune(before: $date)`. Deleting rows invalidates every
 * cached rate lookup, as any write does.
 */
final readonly class PruneExchangeRatesAction
{
    public function __construct(private CacheFactory $caches) {}

    public function execute(PruneExchangeRatesData $data): int
    {
        $query = CurrencyRateModel::query()->where('effective_date', '<', $data->before->format('Y-m-d'));

        if (! $data->includeManual) {
            $query->where('source', '!=', 'manual');
        }

        if ($data->pretend) {
            return $query->count();
        }

        $deleted = $query->delete();

        if ($deleted > 0) {
            RateCacheGeneration::configured($this->caches)?->bumpAfterWrite($query->getModel()->getConnection());
        }

        return $deleted;
    }
}
