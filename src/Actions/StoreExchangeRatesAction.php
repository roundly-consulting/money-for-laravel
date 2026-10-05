<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Money\DataTransferObjects\RefreshResult;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Exchange\RateCacheGeneration;
use RoundlyConsulting\Money\Models\CurrencyRate;
use RoundlyConsulting\Money\Support\CurrencyRateModel;
use RoundlyConsulting\Money\Support\KeepManualRate;
use RoundlyConsulting\Money\Support\StorableRate;

/**
 * The one write path for the rates table — refreshes and manual rates alike:
 *
 * ```php
 * Exchange::rates()->manual('EUR', 'CZK', '25.10', $date);
 * Exchange::rates()->store(...$rates);
 * // or, without the facade:
 * app(StoreExchangeRatesAction::class)->execute([
 *     ExchangeRate::fromDecimal('EUR', 'CZK', '25.10', $date, source: 'manual'),
 * ]);
 * ```
 *
 * Upserts on (base, quote, effective_date). A `manual` row overwrites anything; any other
 * source never overwrites an existing `manual` row — enforced inside the upsert itself, so
 * also for a manual rate saved while the batch is written. A rate without an exact decimal
 * of at most 40 characters (e.g. 1/3) is refused, never rounded. A write invalidates every
 * cached rate lookup ({@see RateCacheGeneration}).
 */
final readonly class StoreExchangeRatesAction
{
    private const int CHUNK = 500;

    public function __construct(private CacheFactory $caches) {}

    /**
     * @param  iterable<ExchangeRate>  $rates
     */
    public function execute(iterable $rates): RefreshResult
    {
        $rows = [];
        $dates = [];
        $source = null;

        foreach ($rates as $rate) {
            $decimal = StorableRate::decimal($rate);
            $date = $rate->date->format('Y-m-d');
            $key = $rate->from->code.'|'.$rate->to->code.'|'.$date;

            $rows[$key] = [
                'base_currency' => $rate->from->code,
                'quote_currency' => $rate->to->code,
                'rate' => $decimal,
                'effective_date' => $date,
                'source' => $rate->source,
            ];
            $dates[$key] = $rate->date;

            $source ??= $rate->source;
        }

        $skippedManual = 0;

        foreach ($this->protectedKeys($rows) as $key) {
            if ($rows[$key]['source'] !== 'manual') {
                unset($rows[$key], $dates[$key]);
                $skippedManual++;
            }
        }

        $latest = null;

        foreach ($dates as $date) {
            $latest = $latest === null || $date->greaterThan($latest) ? $date : $latest;
        }

        $now = CarbonImmutable::now()->toDateTimeString();

        foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
            $query = CurrencyRateModel::query();

            $query->upsert(
                array_map(static fn (array $row): array => [...$row, 'created_at' => $now, 'updated_at' => $now], $chunk),
                ['base_currency', 'quote_currency', 'effective_date'],
                $this->assignments($query),
            );
        }

        if ($rows !== []) {
            RateCacheGeneration::configured($this->caches)?->bumpAfterWrite(CurrencyRateModel::query()->getModel()->getConnection());
        }

        return new RefreshResult($source ?? 'manual', count($rows), $skippedManual, 0, $latest);
    }

    /**
     * The upsert's update assignments. protectedKeys() only filters what it saw: a manual
     * rate saved after it ran would still be overwritten by a plain upsert. So each
     * assignment keeps the stored value when the stored row is manual and the incoming one
     * is not — decided per row inside the one statement, on sqlite, pgsql and MySQL alike.
     * `source` goes last because MySQL evaluates the assignments left to right.
     *
     * @param  Builder<CurrencyRate>  $query
     * @return list<string>|array<string, KeepManualRate>
     */
    private function assignments(Builder $query): array
    {
        $connection = $query->getModel()->getConnection();

        $incoming = match ($connection->getDriverName()) {
            'sqlite', 'pgsql' => 'excluded',
            'mysql', 'mariadb' => $connection->getConfig('use_upsert_alias') ? 'alias' : 'values',
            default => null,
        };

        if ($incoming === null) {
            return ['rate', 'source', 'updated_at'];
        }

        $table = $query->getModel()->getTable();

        return [
            'rate' => new KeepManualRate($table, 'rate', $incoming),
            'updated_at' => new KeepManualRate($table, 'updated_at', $incoming),
            'source' => new KeepManualRate($table, 'source', $incoming),
        ];
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
