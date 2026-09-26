<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Parsing;

use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Contracts\MoneyParser;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Formatting\Locales;
use RoundlyConsulting\Money\Money;

/**
 * A deterministic parser for human-entered amounts. ext-intl only supplies the locale's
 * separators and grouping sizes; without it the `money.formatting.fallback` separators apply.
 *
 * 1. Spaces (incl. NBSP, NNBSP, thin space) and apostrophes are grouping candidates, U+2212
 *    is a minus, bidi marks are dropped.
 * 2. An optional currency token at the start or the end — a code (`EUR`), a registry
 *    symbol (`€`) or, for the `$currency` argument, its symbol in the locale (`Ft` in hu).
 *    An ambiguous symbol (`$`) needs the `$currency` argument.
 * 3. A leading/trailing `-`/`+` or accounting parentheses set the sign.
 * 4. Grouping is accepted only in the integer part and only in well-formed groups.
 * 5. The canonical decimal goes to Money::ofMajor(): excess precision is refused.
 *
 * Digits must be ASCII (0-9): native numbering systems (Arabic-Indic, Devanagari, …) are
 * refused, although the formatter renders them for locales that use them.
 */
final class LocalizedMoneyParser implements MoneyParser
{
    public const int MAX_LENGTH = 128;

    /** Spaces become spaces, U+2019 an apostrophe, U+2212 a minus; bidi marks go. */
    private const array NORMALIZE = [
        "\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2009}" => ' ', "\u{2019}" => "'",
        "\u{2212}" => '-', "\u{200E}" => '', "\u{200F}" => '', "\u{061C}" => '',
    ];

    public function __construct(private readonly CurrencyRegistry $registry) {}

    public function parse(string $input, Currency|string|null $currency = null, ?string $locale = null): Money
    {
        if (strlen($input) > self::MAX_LENGTH) {
            throw InvalidAmount::tooLong(strlen($input), self::MAX_LENGTH);
        }

        $locale = Locales::resolve($locale);
        $expected = $currency === null ? null : ($currency instanceof Currency ? $currency : $this->registry->get($currency));

        $normalized = strtr(trim($input), self::NORMALIZE);

        if (preg_match('/\A(?<prefix>[^0-9]*)(?<number>[0-9](?:.*[0-9])?)(?<suffix>[^0-9]*)\z/su', $normalized, $parts) !== 1) {
            throw InvalidAmount::unparsable($input, 'no digits');
        }

        $prefix = trim($parts['prefix']);
        $suffix = trim($parts['suffix']);

        $negative = $this->negative($input, $prefix, $suffix);
        $token = $this->token($input, $prefix, $suffix);

        $detected = $token === '' ? null : $this->currencyFor($token, $expected, $locale);

        if ($detected !== null && $expected !== null && ! $detected->equals($expected)) {
            throw CurrencyMismatch::between($detected, $expected);
        }

        $currency = $detected ?? $expected ?? $this->registry->get((string) config('money.default_currency'));

        $decimal = $this->decimal($input, $parts['number'], $locale);

        return Money::ofMajor(($negative ? '-' : '').$decimal, $currency);
    }

    /** A leading/trailing minus or accounting parentheses. */
    private function negative(string $input, string $prefix, string $suffix): bool
    {
        $opening = str_contains($prefix, '(');
        $closing = str_contains($suffix, ')');

        if ($opening !== $closing || str_contains($prefix, ')') || str_contains($suffix, '(')) {
            throw InvalidAmount::unparsable($input, 'unbalanced parentheses');
        }

        $minus = substr_count($prefix.$suffix, '-');

        if ($minus > 1 || ($minus === 1 && $opening) || substr_count($prefix.$suffix, '+') > 1 || ($minus === 1 && str_contains($prefix.$suffix, '+'))) {
            throw InvalidAmount::unparsable($input, 'more than one sign');
        }

        return $minus === 1 || $opening;
    }

    /** The currency code or symbol on one side of the number ('' when there is none). */
    private function token(string $input, string $prefix, string $suffix): string
    {
        $prefixToken = trim(str_replace(['(', ')', '-', '+'], '', $prefix));
        $suffixToken = trim(str_replace(['(', ')', '-', '+'], '', $suffix));

        if ($prefixToken !== '' && $suffixToken !== '') {
            throw InvalidAmount::unparsable($input, 'a currency on both sides');
        }

        return $prefixToken.$suffixToken;
    }

    private function currencyFor(string $token, ?Currency $expected, string $locale): Currency
    {
        if (preg_match('/\A[A-Za-z][A-Za-z0-9]{1,9}\z/', $token) === 1 && $this->registry->has($token)) {
            return $this->registry->get($token);
        }

        // The expected currency's symbol — the registry's, or the locale's own ("Ft" in hu,
        // "￥" in ja_JP), so input formatted in that locale reads back.
        if ($expected !== null && ($expected->displaySymbol() === $token || $expected->code === strtoupper($token)
            || $this->localSymbol($expected, $locale) === $token)) {
            return $expected;
        }

        $matches = array_values(array_filter(
            $this->registry->all(),
            static fn (Currency $currency): bool => $currency->symbol === $token,
        ));

        return match (count($matches)) {
            1 => $matches[0],
            0 => throw InvalidAmount::unparsable($token, 'unknown currency'),
            default => throw InvalidAmount::ambiguousCurrency($token),
        };
    }

    private function localSymbol(Currency $currency, string $locale): ?string
    {
        $symbol = NumberSymbols::currencySymbol($locale, $currency);

        return $symbol === null ? null : trim(strtr($symbol, self::NORMALIZE));
    }

    /**
     * The number part as a canonical decimal, validating separators and grouping.
     */
    private function decimal(string $input, string $number, string $locale): string
    {
        $symbols = NumberSymbols::for($locale);
        $decimalSeparator = $symbols->decimal;

        if ($decimalSeparator === '') {
            throw InvalidMoneyConfiguration::invalid('money.formatting.fallback.decimal_separator', 'the decimal separator cannot be empty');
        }

        $grouping = [];

        foreach ([$symbols->grouping, ' ', "'"] as $separator) {
            if ($separator !== '' && $separator !== $decimalSeparator && ! in_array($separator, $grouping, true)) {
                $grouping[] = $separator;
            }
        }

        $pieces = explode($decimalSeparator, $number);

        if (count($pieces) > 2) {
            throw InvalidAmount::unparsable($input, 'more than one decimal separator');
        }

        [$integer, $fraction] = array_pad($pieces, 2, null);

        if ($fraction !== null && preg_match('/\A[0-9]+\z/', $fraction) !== 1) {
            throw InvalidAmount::unparsable($input, 'the fraction may only hold digits');
        }

        $integer = $this->ungroup($input, (string) $integer, $grouping, $symbols->primaryGroup, $symbols->secondaryGroup);

        return $fraction === null ? $integer : $integer.'.'.$fraction;
    }

    /**
     * @param  list<non-empty-string>  $grouping
     */
    private function ungroup(string $input, string $integer, array $grouping, int $primary, int $secondary): string
    {
        if (preg_match('/\A[0-9]+\z/', $integer) === 1) {
            return $integer;
        }

        $used = array_values(array_filter($grouping, static fn (string $separator): bool => str_contains($integer, $separator)));

        if (count($used) !== 1) {
            throw InvalidAmount::unparsable($input, 'unexpected characters in the number');
        }

        $groups = explode($used[0], $integer);
        $last = array_pop($groups);
        $secondary = $secondary > 0 ? $secondary : $primary;

        $valid = strlen($last) === $primary && preg_match('/\A[0-9]+\z/', $last) === 1;

        foreach ($groups as $index => $group) {
            $size = strlen($group);
            $leading = $index === 0;

            $valid = $valid
                && preg_match('/\A[0-9]+\z/', $group) === 1
                && ($leading ? $size >= 1 && $size <= $secondary : $size === $secondary);
        }

        if (! $valid) {
            throw InvalidAmount::unparsable($input, 'misplaced grouping separators');
        }

        return implode('', [...$groups, $last]);
    }
}
