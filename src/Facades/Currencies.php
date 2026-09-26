<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Money\Contracts\CurrencyRegistry;

/**
 * @method static \RoundlyConsulting\Money\Currency get(string $code)
 * @method static \RoundlyConsulting\Money\Currency|null find(string $code)
 * @method static bool has(string $code)
 * @method static \RoundlyConsulting\Money\Currency|null findByNumericCode(int|string $numeric)
 * @method static void register(\RoundlyConsulting\Money\Currency $currency, bool $replace = false)
 * @method static list<\RoundlyConsulting\Money\Currency> all()
 * @method static list<\RoundlyConsulting\Money\Currency> iso()
 * @method static list<\RoundlyConsulting\Money\Currency> custom()
 *
 * @see CurrencyRegistry
 */
final class Currencies extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CurrencyRegistry::class;
    }
}
