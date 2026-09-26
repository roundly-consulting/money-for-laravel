<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Casts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyValue;

/**
 * A `'Y-m-d'` date column. Laravel's `date` cast writes `'Y-m-d H:i:s'`, which on SQLite
 * (TEXT) makes `'2026-09-25 00:00:00' <= '2026-09-25'` false — a real-engine divergence.
 *
 * @internal
 *
 * @implements CastsAttributes<CarbonImmutable|null, mixed>
 */
final class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $date = is_string($value) ? CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10)) : null;

        if (! $date instanceof CarbonImmutable) {
            throw InvalidMoneyValue::corrupt($key, 'expected a Y-m-d date');
        }

        return $date;
    }

    /**
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$key => match (true) {
            $value === null => null,
            $value instanceof CarbonInterface => $value->format('Y-m-d'),
            is_string($value) => $this->get($model, $key, $value, $attributes)?->format('Y-m-d'),
            default => throw InvalidMoneyValue::corrupt($key, 'expected a date'),
        }];
    }
}
