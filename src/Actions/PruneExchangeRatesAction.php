<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Actions;

use RoundlyConsulting\Money\DataTransferObjects\PruneExchangeRatesData;
use RoundlyConsulting\Money\Support\CurrencyRateModel;

/** Deletes (or, pretending, counts) rates older than a date; `manual` rows stay unless asked. */
final class PruneExchangeRatesAction
{
    public function execute(PruneExchangeRatesData $data): int
    {
        $query = CurrencyRateModel::query()->where('effective_date', '<', $data->before->format('Y-m-d'));

        if (! $data->includeManual) {
            $query->where('source', '!=', 'manual');
        }

        return $data->pretend ? $query->count() : $query->delete();
    }
}
