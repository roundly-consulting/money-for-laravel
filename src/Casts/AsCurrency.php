<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyValue;

/**
 * A currency-code column ⇄ a registered {@see Currency}; null ⇄ null.
 *
 * @implements CastsAttributes<Currency|null, mixed>
 */
final class AsCurrency implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Currency
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidMoneyValue::corrupt($key, 'a currency code must be a string');
        }

        return app(CurrencyRegistry::class)->get($value);
    }

    /**
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        $registry = app(CurrencyRegistry::class);

        if (is_string($value)) {
            return [$key => $registry->get($value)->code];
        }

        if (! $value instanceof Currency) {
            throw InvalidMoneyValue::notCurrency($key, $value);
        }

        if (! ($registry->find($value->code)?->equals($value) ?? false)) {
            throw InvalidMoneyValue::unregisteredCurrency($key, $value->code);
        }

        return [$key => $value->code];
    }
}
