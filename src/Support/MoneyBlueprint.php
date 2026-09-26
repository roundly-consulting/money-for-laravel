<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

/**
 * The schema macros — a **frozen contract**: consumer packages publish migrations into host
 * apps that call these by name, with named arguments, forever. They may gain optional
 * trailing parameters; they never change or drop one.
 *
 * - `$table->money('price')` — signed `decimal(P, 0)` minor amount (P = money.schema.precision)
 *   plus `price_currency` varchar; returns the AMOUNT column.
 * - `$table->money('compare_at_price', currency: 'currency', nullable: true)` — a named,
 *   possibly shared currency column (created once per blueprint); `currency: false` skips it.
 * - `$table->moneyJson('snapshot')` — a jsonb column for AsMoneyJson.
 * - `$table->currencyCode('currency')` — a currency code varchar.
 */
final class MoneyBlueprint
{
    public const array MACROS = ['money', 'moneyJson', 'currencyCode'];

    /** Registered unconditionally at boot (migrations depend on it); an existing macro wins. */
    public static function register(): void
    {
        if (! Blueprint::hasMacro('money')) {
            Blueprint::macro('money', function (string $column, string|false|null $currency = null, bool $nullable = false): ColumnDefinition {
                return MoneyBlueprint::money($this, $column, $currency, $nullable);
            });
        }

        if (! Blueprint::hasMacro('moneyJson')) {
            Blueprint::macro('moneyJson', function (string $column, bool $nullable = false): ColumnDefinition {
                return MoneyBlueprint::moneyJson($this, $column, $nullable);
            });
        }

        if (! Blueprint::hasMacro('currencyCode')) {
            Blueprint::macro('currencyCode', function (string $column = 'currency', bool $nullable = false): ColumnDefinition {
                return MoneyBlueprint::currencyCode($this, $column, $nullable);
            });
        }
    }

    public static function money(Blueprint $table, string $column, string|false|null $currency = null, bool $nullable = false): ColumnDefinition
    {
        $amount = $table->decimal($column, Schema::precision(), 0);

        if ($nullable) {
            $amount->nullable();
        }

        if ($currency !== false) {
            $name = $currency ?? $column.'_currency';

            if (! self::hasColumn($table, $name)) {
                self::currencyCode($table, $name, $nullable);
            }
        }

        return $amount;
    }

    public static function moneyJson(Blueprint $table, string $column, bool $nullable = false): ColumnDefinition
    {
        $definition = $table->jsonb($column);

        return $nullable ? $definition->nullable() : $definition;
    }

    public static function currencyCode(Blueprint $table, string $column = 'currency', bool $nullable = false): ColumnDefinition
    {
        $definition = $table->string($column, Schema::currencyLength());

        return $nullable ? $definition->nullable() : $definition;
    }

    private static function hasColumn(Blueprint $table, string $name): bool
    {
        foreach ($table->getAddedColumns() as $added) {
            if ($added->get('name') === $name) {
                return true;
            }
        }

        return false;
    }
}
