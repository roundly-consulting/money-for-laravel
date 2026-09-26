<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Formatting;

use NumberFormatter;
use RoundlyConsulting\Money\Contracts\MoneyFormatter;
use RoundlyConsulting\Money\Enums\CurrencyDisplay;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Math\IntegerString;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Locale-aware formatting through ext-intl (ICU).
 *
 * - The digits shown are always the requested ones (the currency exponent by default):
 *   CLDR's display digits differ from ISO for e.g. HUF, so fraction digits are forced.
 * - Custom currencies (unknown to ICU) render with their own display symbol.
 * - ICU formats doubles, which are exact only to 15 significant digits. Above that the
 *   exact `toDecimal()` digits are rendered with the locale's separators, grouping sizes
 *   and currency affixes read from ICU — so a 38-digit amount formats digit-exact.
 * - One NumberFormatter per (locale, currency, options), LRU-bounded per worker.
 */
final class IntlMoneyFormatter implements MoneyFormatter
{
    /** Significant digits an IEEE double carries exactly. */
    private const int FLOAT_SAFE_DIGITS = 15;

    public const int CACHE_SIZE = 64;

    /** @var array<string, NumberFormatter> */
    private array $formatters = [];

    public function format(Money $money, ?string $locale = null, ?FormatOptions $options = null): string
    {
        $locale = Locales::resolve($locale);

        $options ??= new FormatOptions(
            display: Config::using(InvalidMoneyConfiguration::class)->enum('money.formatting.display', CurrencyDisplay::class),
        );

        $decimal = AmountDigits::resolve($money, $options);
        $formatter = $this->formatter($money, $locale, $options, AmountDigits::fractionDigits($decimal));

        if (IntegerString::digits(str_replace('.', '', ltrim($decimal, '-'))) <= self::FLOAT_SAFE_DIGITS) {
            $formatted = $formatter->format((float) $decimal);

            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return $this->exact($formatter, $decimal, $options);
    }

    /** How many formatters this worker currently holds (bounded by CACHE_SIZE). */
    public function cachedFormatters(): int
    {
        return count($this->formatters);
    }

    private function formatter(Money $money, string $locale, FormatOptions $options, int $digits): NumberFormatter
    {
        $currency = $money->currency();
        $key = implode('|', [$locale, $currency->code, $currency->exponent, $options->display->value, $digits, (int) $options->grouping]);

        if (isset($this->formatters[$key])) {
            $formatter = $this->formatters[$key];
            unset($this->formatters[$key]);

            return $this->formatters[$key] = $formatter;
        }

        if ($options->display === CurrencyDisplay::None) {
            $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        } else {
            $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
            $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency->iso ? $currency->code : 'XXX');

            if ($options->display === CurrencyDisplay::Code) {
                $formatter->setSymbol(NumberFormatter::CURRENCY_SYMBOL, $currency->code);
            } elseif (! $currency->iso) {
                $formatter->setSymbol(NumberFormatter::CURRENCY_SYMBOL, $currency->displaySymbol());
            }
        }

        // Set after the currency code: choosing a currency resets ICU's fraction digits.
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $digits);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);
        $formatter->setAttribute(NumberFormatter::GROUPING_USED, $options->grouping ? 1 : 0);

        $this->formatters[$key] = $formatter;

        if (count($this->formatters) > self::CACHE_SIZE) {
            array_shift($this->formatters);
        }

        return $formatter;
    }

    /**
     * Render exact decimal digits with the locale's symbols and affixes.
     */
    private function exact(NumberFormatter $formatter, string $decimal, FormatOptions $options): string
    {
        $monetary = $options->display !== CurrencyDisplay::None;

        $decimalSeparator = $formatter->getSymbol($monetary ? NumberFormatter::MONETARY_SEPARATOR_SYMBOL : NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
        $groupingSeparator = $formatter->getSymbol($monetary ? NumberFormatter::MONETARY_GROUPING_SEPARATOR_SYMBOL : NumberFormatter::GROUPING_SEPARATOR_SYMBOL);

        $negative = str_starts_with($decimal, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($decimal, '-'), 2), 2, null);

        $number = $options->grouping
            ? AmountDigits::group(
                $integer,
                (string) $groupingSeparator,
                (int) $formatter->getAttribute(NumberFormatter::GROUPING_SIZE),
                (int) $formatter->getAttribute(NumberFormatter::SECONDARY_GROUPING_SIZE),
            )
            : $integer;

        if ($fraction !== null) {
            $number .= $decimalSeparator.$fraction;
        }

        $prefix = $formatter->getTextAttribute($negative ? NumberFormatter::NEGATIVE_PREFIX : NumberFormatter::POSITIVE_PREFIX);
        $suffix = $formatter->getTextAttribute($negative ? NumberFormatter::NEGATIVE_SUFFIX : NumberFormatter::POSITIVE_SUFFIX);

        return $prefix.$number.$suffix;
    }
}
