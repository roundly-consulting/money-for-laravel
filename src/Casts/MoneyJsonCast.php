<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyValue;
use RoundlyConsulting\Money\Math\IntegerString;
use RoundlyConsulting\Money\Money;

/**
 * Stores exactly `{"minor": "<canonical string>", "currency": "<CODE>"}` — `minor` a JSON
 * string so no JSON reader can round it, and no derived `decimal` that could drift.
 *
 * @internal
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
final class MoneyJsonCast implements CastsAttributes, ComparesCastableAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        $data = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($data)) {
            throw InvalidMoneyValue::corrupt($key, 'expected a JSON object with minor and currency');
        }

        if (is_float($data['minor'] ?? null)) {
            throw InvalidMoneyValue::corrupt($key, 'the minor amount decoded to a float (a JSON number beyond PHP_INT_MAX)');
        }

        return Money::fromArray([
            'minor' => $data['minor'] ?? null,
            'decimal' => $data['decimal'] ?? null,
            'currency' => $data['currency'] ?? null,
        ]);
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

        if (! (app(CurrencyRegistry::class)->find($value->currency()->code)?->equals($value->currency()) ?? false)) {
            throw InvalidMoneyValue::unregisteredCurrency($key, $value->currency()->code);
        }

        return [$key => json_encode(['minor' => $value->minor(), 'currency' => $value->currency()->code], JSON_THROW_ON_ERROR)];
    }

    /**
     * Dirty-check by value: pgsql jsonb and MySQL JSON hand the object back re-formatted
     * (`{"minor": "1050", "currency": "EUR"}`), so equal money must not compare by bytes —
     * otherwise merely reading the attribute turns every save into an UPDATE.
     */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        if ($firstValue === null || $secondValue === null) {
            return $firstValue === $secondValue;
        }

        $first = self::identity($firstValue);

        return $first !== null && $first === self::identity($secondValue);
    }

    /**
     * @return array{minor: string, decimal: string, currency: string}|null
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value instanceof Money ? $value->toArray() : null;
    }

    /** "minor|CODE" of a stored value, or null when it is not readable money. */
    private static function identity(mixed $value): ?string
    {
        $data = is_string($value) ? json_decode($value, true) : $value;
        $minor = is_array($data) ? ($data['minor'] ?? null) : null;
        $currency = is_array($data) ? ($data['currency'] ?? null) : null;

        if ((! is_int($minor) && ! is_string($minor)) || ! is_string($currency)) {
            return null;
        }

        try {
            return IntegerString::normalize($minor).'|'.strtoupper(trim($currency));
        } catch (InvalidAmount|AmountOverflow) {
            return null;
        }
    }
}
