<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class InvalidMoneyValue extends MoneyException
{
    public static function notMoney(string $key, mixed $value): self
    {
        $type = get_debug_type($value);

        return new self("[{$key}] expects a Money instance or null, {$type} given. Bare numbers carry no currency and no unit.");
    }

    public static function notCurrency(string $key, mixed $value): self
    {
        $type = get_debug_type($value);

        return new self("[{$key}] expects a Currency, a currency code or null, {$type} given.");
    }

    public static function corrupt(string $key, string $reason): self
    {
        return new self("The stored value of [{$key}] is not a valid money amount: {$reason}. Refusing to round or guess.");
    }

    public static function missingCurrency(string $key, string $column): self
    {
        return new self("[{$key}] has an amount but its currency column [{$column}] is null.");
    }

    public static function unregisteredCurrency(string $key, string $code): self
    {
        return new self("[{$key}] cannot store an amount in [{$code}]: only the code is persisted, so the currency must be registered with the same exponent.");
    }

    public static function currencyColumnConflict(string $key, string $column, string $stored, string $given): self
    {
        return new self("[{$key}] is {$given} but its shared currency column [{$column}] holds {$stored}; set [{$column}] first to re-denominate the row on purpose.");
    }

    public static function exceedsPrecision(string $key, int $digits, int $precision): self
    {
        return new self("[{$key}] has {$digits} digits of minor units; the column holds at most {$precision} (money.schema.precision).");
    }

    public static function exceedsEngineRange(string $driver, string $key): self
    {
        return new self("[{$key}] does not fit a 64-bit integer, which is the exact range of a money column on {$driver}; larger amounts need pgsql or MySQL.");
    }

    public static function wrongCurrency(string $key, string $expected, string $given): self
    {
        return new self("[{$key}] is stored in {$expected}, {$given} given.");
    }
}
