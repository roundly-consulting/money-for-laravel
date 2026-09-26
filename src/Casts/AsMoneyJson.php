<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Casts;

use Illuminate\Contracts\Database\Eloquent\Castable;

/**
 * Cast a JSON column holding `{"minor": "1050", "currency": "EUR"}` to Money — for
 * snapshots and payloads; use {@see AsMoney} for amounts you query or sort by.
 */
final class AsMoneyJson implements Castable
{
    /**
     * @param  array<array-key, mixed>  $arguments
     */
    public static function castUsing(array $arguments): MoneyJsonCast
    {
        return new MoneyJsonCast;
    }
}
