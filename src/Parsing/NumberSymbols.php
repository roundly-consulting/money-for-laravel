<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Parsing;

use NumberFormatter;

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
            return new self(
                (string) config('money.formatting.fallback.decimal_separator'),
                (string) config('money.formatting.fallback.thousands_separator'),
            );
        }

        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);

        return new self(
            (string) $formatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL),
            strtr((string) $formatter->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL), ["\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2019}" => "'"]),
            (int) $formatter->getAttribute(NumberFormatter::GROUPING_SIZE),
            (int) $formatter->getAttribute(NumberFormatter::SECONDARY_GROUPING_SIZE),
        );
    }
}
