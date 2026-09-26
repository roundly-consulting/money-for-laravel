<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Currencies;

use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\CurrencyAlreadyRegistered;
use RoundlyConsulting\Money\Exceptions\InvalidCurrency;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exceptions\UnknownCurrency;
use RoundlyConsulting\Money\Support\Schema;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * The registry the package binds: the bundled ISO 4217 list (unless disabled) plus the
 * custom currencies from config and from `Currencies::register()` calls made while booting.
 */
final class DefaultCurrencyRegistry implements CurrencyRegistry
{
    /** @var array<string, Currency> */
    private array $currencies = [];

    /**
     * @param  list<Currency>  $currencies
     */
    public function __construct(
        array $currencies = [],
        private readonly int $maxCodeLength = 3,
    ) {
        foreach ($currencies as $currency) {
            $this->register($currency);
        }
    }

    /** The registry configured by `money.currencies.*` and `money.schema.currency_length`. */
    public static function fromConfig(): self
    {
        $registry = new self(
            config('money.currencies.iso') === false ? [] : IsoCurrencyData::load(),
            Schema::currencyLength(),
        );

        $custom = config('money.currencies.custom');

        foreach (is_array($custom) ? $custom : [] as $code => $definition) {
            $registry->register(self::customFromConfig((string) $code, $definition));
        }

        return $registry;
    }

    public function get(string $code): Currency
    {
        return $this->find($code) ?? throw UnknownCurrency::code(self::normalize($code));
    }

    public function find(string $code): ?Currency
    {
        return $this->currencies[self::normalize($code)] ?? null;
    }

    public function has(string $code): bool
    {
        return $this->find($code) !== null;
    }

    public function findByNumericCode(int|string $numeric): ?Currency
    {
        $numeric = is_int($numeric) ? $numeric : (ctype_digit($numeric) ? intval($numeric, 10) : -1);

        foreach ($this->currencies as $currency) {
            if ($currency->numericCode === $numeric) {
                return $currency;
            }
        }

        return null;
    }

    public function register(Currency $currency, bool $replace = false): void
    {
        $existing = $this->currencies[$currency->code] ?? null;

        if ($existing !== null && $existing->iso) {
            throw CurrencyAlreadyRegistered::iso($currency->code);
        }

        if ($existing !== null && ! $replace) {
            throw CurrencyAlreadyRegistered::code($currency->code);
        }

        if (strlen($currency->code) > $this->maxCodeLength) {
            throw InvalidCurrency::tooLongForSchema($currency->code, $this->maxCodeLength);
        }

        $this->currencies[$currency->code] = $currency;
    }

    public function all(): array
    {
        return $this->sorted(static fn (Currency $currency): bool => true);
    }

    public function iso(): array
    {
        return $this->sorted(static fn (Currency $currency): bool => $currency->iso);
    }

    public function custom(): array
    {
        return $this->sorted(static fn (Currency $currency): bool => ! $currency->iso);
    }

    /**
     * @param  callable(Currency): bool  $filter
     * @return list<Currency>
     */
    private function sorted(callable $filter): array
    {
        $currencies = array_filter($this->currencies, $filter);

        ksort($currencies, SORT_STRING);

        return array_values($currencies);
    }

    private static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    private static function customFromConfig(string $code, mixed $definition): Currency
    {
        if (! is_array($definition)) {
            throw InvalidMoneyConfiguration::invalid("money.currencies.custom.{$code}", 'expected an array with an exponent');
        }

        $values = Config::for($definition, InvalidMoneyConfiguration::class);
        $name = $definition['name'] ?? null;
        $symbol = $definition['symbol'] ?? null;

        if (($name !== null && ! is_string($name)) || ($symbol !== null && ! is_string($symbol))) {
            throw InvalidMoneyConfiguration::invalid("money.currencies.custom.{$code}", 'name and symbol must be strings');
        }

        return Currency::custom(
            $code,
            $values->intBetween('exponent', 0, Currency::MAX_CUSTOM_EXPONENT, 0),
            $name,
            $symbol,
        );
    }
}
