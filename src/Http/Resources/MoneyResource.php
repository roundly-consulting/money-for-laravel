<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Money\Contracts\MoneyFormatter;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyValue;
use RoundlyConsulting\Money\Money;

/**
 * The API-response shape of an amount:
 * `{"minor":"1050","decimal":"10.50","currency":"EUR","exponent":2,"formatted":"10,50 €"}`.
 *
 * `minor` is a JSON string (JavaScript numbers lose integers above 2^53); `formatted` is
 * presentation only — clients compute with `minor`/`decimal`.
 */
final class MoneyResource extends JsonResource
{
    /** Null in, null out (JsonResource::make(null) would render []). */
    public static function from(?Money $money): ?self
    {
        return $money === null ? null : new self($money);
    }

    /**
     * @return array{minor: string, decimal: string, currency: string, exponent: int, formatted: string}
     */
    public function toArray(Request $request): array
    {
        $money = $this->resource;

        if (! $money instanceof Money) {
            throw InvalidMoneyValue::notMoney('MoneyResource', $money);
        }

        return [
            'minor' => $money->minor(),
            'decimal' => $money->toDecimal(),
            'currency' => $money->currency()->code,
            'exponent' => $money->currency()->exponent,
            'formatted' => app(MoneyFormatter::class)->format($money),
        ];
    }
}
