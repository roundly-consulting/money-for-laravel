<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Support\MoneyConfig;

/**
 * A registered currency code, optionally restricted to an allow-list (default:
 * money.currencies.allowed, else the whole registry) and/or to ISO 4217.
 *
 * String alias: `currency_code` (`currency_code:EUR,USD` for an allow-list).
 */
final class CurrencyCode implements ValidationRule
{
    private bool $isoOnly = false;

    /**
     * @param  list<string>|null  $allowed
     */
    public function __construct(private readonly ?array $allowed = null) {}

    public function isoOnly(): self
    {
        $this->isoOnly = true;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $currency = is_string($value) ? app(CurrencyRegistry::class)->find($value) : null;

        if ($currency === null
            || ($this->isoOnly && ! $currency->iso)
            || ! in_array($currency->code, $this->allowed() ?? [$currency->code], true)) {
            $fail('money::validation.currency')->translate();
        }
    }

    /** @return list<string>|null */
    private function allowed(): ?array
    {
        if ($this->allowed === null) {
            return MoneyConfig::allowedCurrencies();
        }

        return array_map(static fn (string $code): string => strtoupper(trim($code)), $this->allowed);
    }
}
