<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Formatting;

use NumberFormatter;
use RoundlyConsulting\Money\Contracts\MoneyFormatter;
use RoundlyConsulting\Money\Enums\CurrencyDisplay;
use RoundlyConsulting\Money\Exceptions\FormatterUnavailable;
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
            display: Config::using(InvalidMoneyConfiguration::class)->enum('money.formatting.display', CurrencyDisplay::class, CurrencyDisplay::Symbol),
        );

        $decimal = AmountDigits::resolve($money, $options);
        $formatter = $this->formatter($money, $locale, $options, AmountDigits::fractionDigits($decimal));

        if (IntegerString::digits(str_replace('.', '', ltrim($decimal, '-'))) <= self::FLOAT_SAFE_DIGITS) {
            $formatted = $formatter->format((float) $decimal);

            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return $this->exact($formatter, $decimal);
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
     * Render the exact decimal digits through ICU's own layout: ICU formats a template with
     * the same sign and digit layout (10^(n-1), or 10^-k for a zero integer part), which
     * places the affixes, currency spacing, grouping and bidi marks and picks the numbering
     * system; the exact digits then replace the template's digits one by one.
     */
    private function exact(NumberFormatter $formatter, string $decimal): string
    {
        $negative = str_starts_with($decimal, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($decimal, '-'), 2), 2, '');

        $template = $integer === '0'
            ? 10 ** -strlen($fraction)
            : (float) ('1'.str_repeat('0', strlen($integer) - 1));

        $rendered = $formatter->format($negative ? -$template : $template);

        if (! is_string($rendered)) {
            throw FormatterUnavailable::exact($decimal);
        }

        // Only the number between the affixes: a currency symbol may itself hold digits.
        $prefix = (string) $formatter->getTextAttribute($negative ? NumberFormatter::NEGATIVE_PREFIX : NumberFormatter::POSITIVE_PREFIX);
        $suffix = (string) $formatter->getTextAttribute($negative ? NumberFormatter::NEGATIVE_SUFFIX : NumberFormatter::POSITIVE_SUFFIX);

        if (! str_starts_with($rendered, $prefix) || ! str_ends_with($rendered, $suffix) || strlen($prefix) + strlen($suffix) > strlen($rendered)) {
            [$prefix, $suffix] = ['', ''];
        }

        $number = substr($rendered, strlen($prefix), strlen($rendered) - strlen($prefix) - strlen($suffix));

        // ICU's digit symbols 0..9 (UNUM_ZERO_DIGIT_SYMBOL, then UNUM_ONE..NINE_DIGIT_SYMBOL = 18..26).
        $symbols = [];

        for ($digit = 0; $digit <= 9; $digit++) {
            $symbols[] = (string) $formatter->getSymbol($digit === 0 ? NumberFormatter::ZERO_DIGIT_SYMBOL : 17 + $digit);
        }

        $digits = str_split($integer.$fraction);
        $characters = mb_str_split($number);
        $next = 0;

        foreach ($characters as $index => $character) {
            if (in_array($character, $symbols, true) && isset($digits[$next])) {
                $characters[$index] = $symbols[(int) $digits[$next++]];
            }
        }

        if ($next !== count($digits)) {
            throw FormatterUnavailable::exact($decimal);
        }

        return $prefix.implode('', $characters).$suffix;
    }
}
