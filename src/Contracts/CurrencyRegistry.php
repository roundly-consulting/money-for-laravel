<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Contracts;

use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\UnknownCurrency;

/**
 * The set of currencies an application knows: the bundled ISO 4217 list plus custom ones.
 * Lookups trim and upper-case the code. Mutate only while booting (Octane workers share it).
 */
interface CurrencyRegistry
{
    /** @throws UnknownCurrency */
    public function get(string $code): Currency;

    public function find(string $code): ?Currency;

    public function has(string $code): bool;

    public function findByNumericCode(int|string $numeric): ?Currency;

    public function register(Currency $currency, bool $replace = false): void;

    /** @return list<Currency> sorted by code */
    public function all(): array;

    /** @return list<Currency> sorted by code */
    public function iso(): array;

    /** @return list<Currency> sorted by code */
    public function custom(): array;
}
