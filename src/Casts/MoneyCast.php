<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyValue;
use RoundlyConsulting\Money\Math\IntegerString;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Support\Schema;

/**
 * The cast behind {@see AsMoney}. Strict both ways:
 *
 * - writes accept only Money|null, in a registered currency (only the code is stored);
 * - the amount is written as the canonical minor string, guarded against the column
 *   precision and — on SQLite, which silently turns wide integers into REAL — int64;
 * - a named (possibly shared) currency column is never silently re-denominated;
 * - reads accept ints and integer strings only; a float, fraction or over-long value is
 *   corrupt data and is refused, never rounded; an amount without a currency is refused.
 *
 * @internal
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
final class MoneyCast implements CastsAttributes, ComparesCastableAttributes, SerializesCastableAttributes
{
    public const string MODE_COLUMN = 'column';

    public const string MODE_FIXED = 'fixed';

    public const string MODE_CONFIG = 'config';

    public const string MODE_ATTRIBUTE = 'attribute';

    public function __construct(
        private readonly string $mode,
        private readonly ?string $value = null,
    ) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::ofMinor(self::storedAmount($key, $value), $this->currencyForRead($model, $key, $attributes));
    }

    /**
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (! $value instanceof Money) {
            throw InvalidMoneyValue::notMoney($key, $value);
        }

        $currency = $value->currency();

        if (! (app(CurrencyRegistry::class)->find($currency->code)?->equals($currency) ?? false)) {
            throw InvalidMoneyValue::unregisteredCurrency($key, $currency->code);
        }

        $precision = Schema::precision();

        if ($value->digits() > $precision) {
            throw InvalidMoneyValue::exceedsPrecision($key, $value->digits(), $precision);
        }

        if ($model->getConnection()->getDriverName() === 'sqlite' && ! $value->fitsInt()) {
            throw InvalidMoneyValue::exceedsEngineRange('sqlite', $key);
        }

        if ($this->mode !== self::MODE_COLUMN) {
            $expected = $this->currencyForWrite($model, $key);

            if (! $expected->equals($currency)) {
                throw CurrencyMismatch::between($expected, $currency);
            }

            return [$key => $value->minor()];
        }

        $column = $this->currencyColumn($key);
        $stored = $attributes[$column] ?? null;

        // A named column may be shared: refuse to re-denominate what it already holds.
        if ($this->value !== null && is_string($stored) && $stored !== '' && strtoupper($stored) !== $currency->code) {
            throw CurrencyMismatch::column($column, strtoupper($stored), $currency->code);
        }

        return [$key => $value->minor(), $column => $currency->code];
    }

    /**
     * @return array{minor: string, decimal: string, currency: string}|null
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value instanceof Money ? $value->toArray() : null;
    }

    /**
     * Dirty-check by normalised amount: `1050` (SQLite), `"1050"` (pgsql/MySQL) and
     * `"01050"` are the same stored value, so re-assigning it is not an UPDATE.
     */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        if ($firstValue === null || $secondValue === null) {
            return $firstValue === $secondValue;
        }

        return self::normalized($firstValue) === self::normalized($secondValue);
    }

    private static function normalized(mixed $value): ?string
    {
        try {
            return is_int($value) || is_string($value) ? IntegerString::normalize($value) : null;
        } catch (InvalidAmount|AmountOverflow) {
            return null;
        }
    }

    private static function storedAmount(string $key, mixed $value): string
    {
        if (is_float($value)) {
            throw InvalidMoneyValue::corrupt($key, 'a floating-point value cannot hold an exact amount');
        }

        if (! is_int($value) && ! is_string($value)) {
            throw InvalidMoneyValue::corrupt($key, get_debug_type($value).' is not an amount');
        }

        try {
            return IntegerString::normalize($value);
        } catch (InvalidAmount|AmountOverflow) {
            throw InvalidMoneyValue::corrupt($key, 'expected an integer number of minor units of at most 65 digits');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function currencyForRead(Model $model, string $key, array $attributes): Currency
    {
        if ($this->mode !== self::MODE_COLUMN) {
            return $this->currencyForWrite($model, $key);
        }

        $column = $this->currencyColumn($key);
        $code = $attributes[$column] ?? null;

        if (! is_string($code) || $code === '') {
            throw InvalidMoneyValue::missingCurrency($key, $column);
        }

        return app(CurrencyRegistry::class)->get($code);
    }

    private function currencyForWrite(Model $model, string $key): Currency
    {
        $currency = match ($this->mode) {
            self::MODE_FIXED => $this->value,
            self::MODE_CONFIG => config((string) $this->value),
            default => $model->getAttribute((string) $this->value),
        };

        if ($currency instanceof Currency) {
            return $currency;
        }

        // Not set — null or blank (a config key's `KEY=` included) — is a missing currency.
        if (! is_string($currency) || trim($currency) === '') {
            throw InvalidMoneyValue::missingCurrency($key, (string) $this->value);
        }

        return app(CurrencyRegistry::class)->get($currency);
    }

    private function currencyColumn(string $key): string
    {
        return $this->value ?? $key.'_currency';
    }
}
