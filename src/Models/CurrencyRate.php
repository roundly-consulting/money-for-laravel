<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Money\Casts\DateOnly;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Database\Factories\CurrencyRateFactory;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Support\MoneyConfig;

/**
 * One stored exchange rate: `1 base = rate × quote` on `effective_date`.
 *
 * Swappable via `money.exchange.providers.database.model` (hence not final). No
 * SoftDeletes, deliberately: this is reference/cache data under a `(pair, date)` unique
 * key, and a soft-deleted row would still occupy that key.
 *
 * @property int $id
 * @property string $base_currency
 * @property string $quote_currency
 * @property string $rate canonical decimal, e.g. "1.0854"
 * @property CarbonImmutable $effective_date
 * @property string $source 'ecb' | 'manual' | a driver name
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class CurrencyRate extends Model
{
    /** @use HasFactory<CurrencyRateFactory> */
    use HasFactory;

    protected $guarded = [];

    public function getTable(): string
    {
        return MoneyConfig::table();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePair(Builder $query, Currency|string $base, Currency|string $quote): void
    {
        $query->where('base_currency', $base instanceof Currency ? $base->code : strtoupper(trim($base)))
            ->where('quote_currency', $quote instanceof Currency ? $quote->code : strtoupper(trim($quote)));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeEffectiveOnOrBefore(Builder $query, CarbonInterface $date): void
    {
        $query->where('effective_date', '<=', $date->format('Y-m-d'));
    }

    public function toExchangeRate(): ExchangeRate
    {
        return ExchangeRate::fromDecimal($this->base_currency, $this->quote_currency, $this->rate, $this->effective_date, $this->source);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['effective_date' => DateOnly::class];
    }

    protected static function newFactory(): CurrencyRateFactory
    {
        return CurrencyRateFactory::new();
    }
}
