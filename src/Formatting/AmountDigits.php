<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Formatting;

use RoundlyConsulting\Money\Math\IntegerString;
use RoundlyConsulting\Money\Math\MinorUnits;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Support\MoneyConfig;

/**
 * The exact decimal a formatter renders: the amount at the requested fraction digits
 * (rounded once when fewer than the exponent), trimmed to a whole number when asked.
 *
 * @internal
 */
final class AmountDigits
{
    public static function resolve(Money $money, FormatOptions $options): string
    {
        $digits = $options->fractionDigits ?? $money->currency()->exponent;
        $digits = max(0, min(MinorUnits::MAX_SCALE, $digits));

        $rounding = $options->rounding ?? MoneyConfig::rounding();
        $decimal = IntegerString::toDecimal($money->toScaled($digits, $rounding), $digits);

        if ($options->trimTrailingZeros && $digits > 0 && str_ends_with($decimal, '.'.str_repeat('0', $digits))) {
            return substr($decimal, 0, -($digits + 1));
        }

        return $decimal;
    }

    /** Fraction digits of a resolved decimal. */
    public static function fractionDigits(string $decimal): int
    {
        $point = strpos($decimal, '.');

        return $point === false ? 0 : strlen($decimal) - $point - 1;
    }

    /**
     * Group integer digits: "1234567" → "1,234,567"; with a secondary size of 2 (Indian
     * grouping) → "12,34,567".
     */
    public static function group(string $digits, string $separator, int $primary = 3, int $secondary = 0): string
    {
        if ($primary < 1 || strlen($digits) <= $primary) {
            return $digits;
        }

        $secondary = $secondary > 0 ? $secondary : $primary;
        $groups = [substr($digits, -$primary)];
        $rest = substr($digits, 0, -$primary);

        while (strlen($rest) > $secondary) {
            array_unshift($groups, substr($rest, -$secondary));
            $rest = substr($rest, 0, -$secondary);
        }

        array_unshift($groups, $rest);

        return implode($separator, $groups);
    }
}
