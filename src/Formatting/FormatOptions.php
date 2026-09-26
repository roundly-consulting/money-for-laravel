<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Formatting;

use RoundingMode;
use RoundlyConsulting\Money\Enums\CurrencyDisplay;

final readonly class FormatOptions
{
    /**
     * @param  int|null  $fractionDigits  null = the currency exponent
     * @param  bool  $trimTrailingZeros  "€10" instead of "€10.00" when the amount is whole
     * @param  RoundingMode|null  $rounding  when fractionDigits < exponent; null = money.rounding
     */
    public function __construct(
        public CurrencyDisplay $display = CurrencyDisplay::Symbol,
        public ?int $fractionDigits = null,
        public bool $trimTrailingZeros = false,
        public bool $grouping = true,
        public ?RoundingMode $rounding = null,
    ) {}

    /** "EUR 10.00" / "10,00 EUR". */
    public static function code(): self
    {
        return new self(display: CurrencyDisplay::Code);
    }

    /** The number alone: "10.00". */
    public static function none(): self
    {
        return new self(display: CurrencyDisplay::None);
    }

    /** "€10" for whole amounts, "€10.50" otherwise. */
    public static function whole(): self
    {
        return new self(trimTrailingZeros: true);
    }
}
