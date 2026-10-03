<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Parsing;

use NumberFormatter;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Support\MoneyConfig;

/**
 * A locale's number separators and grouping sizes, as the parser needs them.
 *
 * @internal
 */
final readonly class NumberSymbols
{
    public function __construct(
        public string $decimal,
        public string $grouping,
        public int $primaryGroup = 3,
        public int $secondaryGroup = 0,
    ) {}

    public static function for(string $locale): self
    {
        if (! extension_loaded('intl')) {
            return new self(MoneyConfig::decimalSeparator(), MoneyConfig::thousandsSeparator());
        }

        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);

        return new self(
            (string) $formatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL),
            strtr((string) $formatter->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL), ["\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2019}" => "'"]),
            (int) $formatter->getAttribute(NumberFormatter::GROUPING_SIZE),
            (int) $formatter->getAttribute(NumberFormatter::SECONDARY_GROUPING_SIZE),
        );
    }

    /** The locale's own symbol for an ISO currency ("Ft" in hu); null without ext-intl. */
    public static function currencySymbol(string $locale, Currency $currency): ?string
    {
        if (! extension_loaded('intl') || ! $currency->iso) {
            return null;
        }

        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency->code);

        // getSymbol() is string|false at runtime; false (an ICU error) means no symbol.
        $symbol = (string) $formatter->getSymbol(NumberFormatter::CURRENCY_SYMBOL);

        return $symbol !== '' ? $symbol : null;
    }
}
