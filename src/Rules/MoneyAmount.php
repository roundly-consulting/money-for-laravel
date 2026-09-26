<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use RoundingMode;
use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Contracts\MoneyParser;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\MoneyException;
use RoundlyConsulting\Money\Math\DecimalString;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Support\Schema;

/**
 * Validates a major-unit amount destined for a money column:
 *
 * ```php
 * 'price' => ['required', MoneyAmount::in('EUR')->min('0.01')->max('99999.99')],
 * 'fee'   => [MoneyAmount::inCurrencyFrom('currency')->positive()],
 * ```
 *
 * Accepts an int, a plain decimal string or a float (via its shortest round-trip decimal;
 * floats at or above 2^53 are refused). Rejects exponent notation, grouping (unless
 * `localized()`), more fraction digits than the currency has, more minor digits than
 * `money.schema.precision`, negatives (unless `allowNegative()`) and unknown currencies —
 * with `inCurrencyFrom()`, also currencies outside `money.currencies.allowed`.
 *
 * String alias: `money_amount:EUR`.
 */
final class MoneyAmount implements DataAwareRule, ValidationRule
{
    private ?string $currencyField = null;

    private Currency|string|null $currency = null;

    private Money|int|string|null $min = null;

    private Money|int|string|null $max = null;

    private bool $positive = false;

    private bool $allowNegative = false;

    private bool $localized = false;

    private ?string $locale = null;

    /** @var array<string, mixed> */
    private array $data = [];

    private function __construct() {}

    public static function in(Currency|string $currency): self
    {
        $rule = new self;
        $rule->currency = $currency;

        return $rule;
    }

    /** The currency is another input field (e.g. a `currency` select next to the amount). */
    public static function inCurrencyFrom(string $field): self
    {
        $rule = new self;
        $rule->currencyField = $field;

        return $rule;
    }

    /** Lower bound; ints and decimal strings are major units. */
    public function min(Money|int|string $min): self
    {
        $this->min = $min;

        return $this;
    }

    public function max(Money|int|string $max): self
    {
        $this->max = $max;

        return $this;
    }

    /** Strictly greater than zero. */
    public function positive(): self
    {
        $this->positive = true;

        return $this;
    }

    public function allowNegative(): self
    {
        $this->allowNegative = true;

        return $this;
    }

    /** Accept human input such as "1 234,50" through the MoneyParser. */
    public function localized(?string $locale = null): self
    {
        $this->localized = true;
        $this->locale = $locale;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $currency = $this->resolveCurrency();

        if ($currency === null) {
            $fail('money::validation.currency')->translate();

            return;
        }

        $money = $this->money($value, $currency, $fail);

        if ($money === null) {
            return;
        }

        if ($money->digits() > Schema::precision()) {
            $fail('money::validation.too_large')->translate(['digits' => (string) Schema::precision()]);

            return;
        }

        if ($money->isNegative() && ! $this->allowNegative) {
            $fail('money::validation.negative')->translate();

            return;
        }

        if ($this->positive && ! $money->isPositive()) {
            $fail('money::validation.positive')->translate();

            return;
        }

        // With inCurrencyFrom() the input picks the currency: a Money bound in another
        // currency cannot be compared, which is a validation failure, not an exception.
        foreach ([$this->min, $this->max] as $bound) {
            if ($bound instanceof Money && ! $bound->currency()->equals($currency)) {
                $fail('money::validation.currency')->translate();

                return;
            }
        }

        $min = $this->bound($this->min, $currency, RoundingMode::PositiveInfinity);

        if ($min !== null && $money->isLessThan($min)) {
            $fail('money::validation.min')->translate(['min' => $min->toDecimal()]);

            return;
        }

        $max = $this->bound($this->max, $currency, RoundingMode::NegativeInfinity);

        if ($max !== null && $money->isGreaterThan($max)) {
            $fail('money::validation.max')->translate(['max' => $max->toDecimal()]);
        }
    }

    private function resolveCurrency(): ?Currency
    {
        if ($this->currencyField === null) {
            return $this->currency instanceof Currency
                ? $this->currency
                : app(CurrencyRegistry::class)->find((string) $this->currency);
        }

        // The input picks the currency, so it is held to the input allow-list.
        $input = data_get($this->data, $this->currencyField);
        $currency = is_string($input) ? app(CurrencyRegistry::class)->find($input) : null;
        $allowed = config('money.currencies.allowed');

        if ($currency === null || ! is_array($allowed)) {
            return $currency;
        }

        $codes = array_map(static fn (mixed $code): string => strtoupper(trim(is_string($code) ? $code : '')), $allowed);

        return in_array($currency->code, $codes, true) ? $currency : null;
    }

    private function money(mixed $value, Currency $currency, Closure $fail): ?Money
    {
        try {
            if ($this->localized && is_string($value)) {
                return app(MoneyParser::class)->parse($value, $currency, $this->locale);
            }

            $decimal = match (true) {
                is_int($value) => (string) $value,
                is_float($value) => DecimalString::fromFloat($value),
                is_string($value) && DecimalString::isValid($value) => DecimalString::normalize($value),
                default => null,
            };
        } catch (MoneyException) {
            $decimal = null;
        }

        if ($decimal === null) {
            $fail('money::validation.amount')->translate();

            return null;
        }

        if (DecimalString::scale($decimal) > $currency->exponent) {
            $fail('money::validation.precision')->translate(['digits' => (string) $currency->exponent]);

            return null;
        }

        try {
            return Money::ofMajor($decimal, $currency);
        } catch (MoneyException) {
            $fail('money::validation.too_large')->translate(['digits' => (string) Schema::precision()]);

            return null;
        }
    }

    /**
     * A major-unit bound in the validated currency. A bound finer than the currency (0.01 in
     * JPY) rounds inwards — up for min, down for max — which is exact for whole minor units:
     * "at least 0.01 JPY" is "at least 1 JPY".
     */
    private function bound(Money|int|string|null $bound, Currency $currency, RoundingMode $inwards): ?Money
    {
        if ($bound === null || $bound instanceof Money) {
            return $bound;
        }

        return Money::ofMajor($bound, $currency, $inwards);
    }
}
