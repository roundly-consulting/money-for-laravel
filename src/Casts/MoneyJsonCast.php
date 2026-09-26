<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyValue;
use RoundlyConsulting\Money\Money;

/**
 * Stores exactly `{"minor": "<canonical string>", "currency": "<CODE>"}` — `minor` a JSON
 * string so no JSON reader can round it, and no derived `decimal` that could drift.
 *
 * @internal
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
final class MoneyJsonCast implements CastsAttributes, SerializesCastableAttributes
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
     * @return array{minor: string, decimal: string, currency: string}|null
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value instanceof Money ? $value->toArray() : null;
    }
}
