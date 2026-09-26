<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money;

use JsonSerializable;
use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Exceptions\InvalidCurrency;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Math\Calculator;
use RoundlyConsulting\Money\Math\IntegerString;
use Stringable;

/**
 * A currency: code, minor-unit exponent, name, display symbol, ISO numeric code. Identity
 * is code **and** exponent — two definitions of "EUR" with different exponents are not the
 * same currency, because the exponent decides what a stored minor amount means.
 */
final readonly class Currency implements JsonSerializable, Stringable
{
    public const int MAX_ISO_EXPONENT = 4;

    public const int MAX_CUSTOM_EXPONENT = 18;

    public string $code;

    public int $exponent;

    public string $name;

    public ?string $symbol;

    public ?int $numericCode;

    public bool $iso;

    public function __construct(
        string $code,
        int $exponent,
        string $name,
        ?string $symbol = null,
        ?int $numericCode = null,
        bool $iso = false,
    ) {
        $code = strtoupper(trim($code));

        $pattern = $iso ? '/\A[A-Z]{3}\z/' : '/\A[A-Z][A-Z0-9]{1,9}\z/';

        if (preg_match($pattern, $code) !== 1) {
            throw InvalidCurrency::code($code, $iso);
        }

        $maxExponent = $iso ? self::MAX_ISO_EXPONENT : self::MAX_CUSTOM_EXPONENT;

        if ($exponent < 0 || $exponent > $maxExponent) {
            throw InvalidCurrency::exponent($code, $exponent, $maxExponent);
        }

        if ($iso && ($numericCode === null || $numericCode < 1 || $numericCode > 999)) {
            throw InvalidCurrency::numeric($code, $numericCode);
        }

        if (trim($name) === '') {
            throw InvalidCurrency::name($code);
        }

        if ($symbol !== null && ($symbol === '' || mb_strlen($symbol) > 8)) {
            throw InvalidCurrency::symbol($code, $symbol);
        }

        $this->code = $code;
        $this->exponent = $exponent;
        $this->name = trim($name);
        $this->symbol = $symbol;
        $this->numericCode = $numericCode;
        $this->iso = $iso;
    }

    /** Look a code up in the bound registry (`'gbp'` works); throws UnknownCurrency. */
    public static function of(string $code): self
    {
        return app(CurrencyRegistry::class)->get($code);
    }

    /** A non-ISO currency: points, credits, crypto. Exponent 0..18; name defaults to the code. */
    public static function custom(string $code, int $exponent, ?string $name = null, ?string $symbol = null): self
    {
        return new self($code, $exponent, $name ?? strtoupper(trim($code)), $symbol);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code && $this->exponent === $other->exponent;
    }

    public function displaySymbol(): string
    {
        return $this->symbol ?? $this->code;
    }

    /** The ISO numeric code zero-padded to three digits ("048" for BHD), or null. */
    public function numericCodeString(): ?string
    {
        return $this->numericCode === null ? null : str_pad((string) $this->numericCode, 3, '0', STR_PAD_LEFT);
    }

    /** Minor units per major unit, as a string like every amount: "100" for EUR. */
    public function minorPerMajor(): string
    {
        return Calculator::pow10($this->exponent);
    }

    /**
     * The largest |major| amount a `decimal($precision, 0)` minor-unit column holds in this
     * currency: (38) at exponent 2 → "999999999999999999999999999999999999.99".
     */
    public function maxMajorAmount(int $precision): string
    {
        if ($precision < 19 || $precision > IntegerString::MAX_DIGITS) {
            throw InvalidMoneyConfiguration::precision($precision);
        }

        return IntegerString::toDecimal(str_repeat('9', $precision), $this->exponent);
    }

    public function __toString(): string
    {
        return $this->code;
    }

    public function jsonSerialize(): string
    {
        return $this->code;
    }
}
