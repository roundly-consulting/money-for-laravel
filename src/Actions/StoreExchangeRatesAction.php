<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Actions;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Money\DataTransferObjects\RefreshResult;
use RoundlyConsulting\Money\Exceptions\InvalidExchangeRate;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Support\CurrencyRateModel;

/**
 * The one write path for the rates table — refreshes and manual rates alike:
 *
 * ```php
 * app(StoreExchangeRatesAction::class)->execute([
 *     ExchangeRate::fromDecimal('EUR', 'CZK', '25.10', $date, source: 'manual'),
 * ]);
 * ```
 *
 * Upserts on (base, quote, effective_date). A `manual` row overwrites anything; any other
 * source never overwrites an existing `manual` row. A rate without an exact decimal of at
 * most 40 characters (e.g. 1/3) is refused, never rounded.
 */
final class StoreExchangeRatesAction
{
    private const int CHUNK = 500;

    /**
     * @param  iterable<ExchangeRate>  $rates
     */
    public function execute(iterable $rates): RefreshResult
    {
        $rows = [];
        $source = null;
        $latest = null;

        foreach ($rates as $rate) {
            $decimal = $rate->rate->toExactDecimal(38);

            if ($decimal === null || strlen($decimal) > 40) {
                throw InvalidExchangeRate::notStorable((string) $rate->rate);
            }

            $date = $rate->date->format('Y-m-d');
            $rows[$rate->from->code.'|'.$rate->to->code.'|'.$date] = [
                'base_currency' => $rate->from->code,
                'quote_currency' => $rate->to->code,
                'rate' => $decimal,
                'effective_date' => $date,
                'source' => $rate->source,
            ];

            $source ??= $rate->source;
            $latest = $latest === null || $rate->date->greaterThan($latest) ? $rate->date : $latest;
        }

        $skippedManual = 0;

        foreach ($this->protectedKeys($rows) as $key) {
            if ($rows[$key]['source'] !== 'manual') {
                unset($rows[$key]);
                $skippedManual++;
            }
        }

        $now = CarbonImmutable::now()->toDateTimeString();

        foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
            CurrencyRateModel::query()->upsert(
                array_map(static fn (array $row): array => [...$row, 'created_at' => $now, 'updated_at' => $now], $chunk),
                ['base_currency', 'quote_currency', 'effective_date'],
                ['rate', 'source', 'updated_at'],
            );
        }

        return new RefreshResult($source ?? 'manual', count($rows), $skippedManual, 0, $latest);
    }

    /**
     * Keys of incoming rows that already exist as `manual` rows.
     *
     * @param  array<string, array{base_currency: string, quote_currency: string, rate: string, effective_date: string, source: string}>  $rows
     * @return list<string>
     */
    private function protectedKeys(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $dates = array_column($rows, 'effective_date');

        $existing = CurrencyRateModel::query()
            ->where('source', 'manual')
            ->whereIn('base_currency', array_values(array_unique(array_column($rows, 'base_currency'))))
            ->whereIn('quote_currency', array_values(array_unique(array_column($rows, 'quote_currency'))))
            ->where('effective_date', '>=', min($dates))
            ->where('effective_date', '<=', max($dates))
            ->get();

        $keys = [];

        foreach ($existing as $row) {
            $key = $row->base_currency.'|'.$row->quote_currency.'|'.$row->effective_date->format('Y-m-d');

            if (isset($rows[$key])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
