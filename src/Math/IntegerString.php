<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Math;

use RoundlyConsulting\Money\Exceptions\AmountOverflow;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;

/**
 * The canonical integer grammar every minor amount is held in:
 *
 *     canonical := "0" | "-"? [1-9] [0-9]{0,64}
 *
 * No `+`, no leading zeros, no whitespace, ASCII digits only, and no negative zero. Because
 * the form is canonical, two amounts compare by sign, then length, then bytes — no bcmath.
 *
 * @internal
 */
final class IntegerString
{
    /** The in-memory cap on significant minor digits (= MySQL's maximum DECIMAL precision). */
    public const int MAX_DIGITS = 65;

    private const string INT_MAX = '9223372036854775807';

    private const string INT_MIN_ABS = '9223372036854775808';

    /**
     * Normalise an `int` or a loose integer string (`^-?[0-9]+$`, leading zeros allowed) into
     * the canonical form, enforcing the digit cap.
     */
    public static function normalize(int|string $value, int $maxDigits = self::MAX_DIGITS): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (preg_match('/\A-?[0-9]+\z/', $value) !== 1) {
            throw InvalidAmount::notInteger($value);
        }

        $negative = $value[0] === '-';
        $digits = ltrim($negative ? substr($value, 1) : $value, '0');

        if ($digits === '') {
            return '0';
        }

        if (strlen($digits) > $maxDigits) {
            throw AmountOverflow::digits('normalize', $maxDigits);
        }

        return $negative ? '-'.$digits : $digits;
    }

    public static function isCanonical(string $value): bool
    {
        return preg_match('/\A(0|-?[1-9][0-9]{0,64})\z/', $value) === 1;
    }

    /** Significant digits of |value|; "0" has one. */
    public static function digits(string $canonical): int
    {
        return strlen(ltrim($canonical, '-'));
    }

    public static function isNegative(string $canonical): bool
    {
        return $canonical[0] === '-';
    }

    public static function isZero(string $canonical): bool
    {
        return $canonical === '0';
    }

    public static function sign(string $canonical): int
    {
        return match (true) {
            $canonical === '0' => 0,
            $canonical[0] === '-' => -1,
            default => 1,
        };
    }

    public static function negate(string $canonical): string
    {
        if ($canonical === '0') {
            return '0';
        }

        return $canonical[0] === '-' ? substr($canonical, 1) : '-'.$canonical;
    }

    public static function abs(string $canonical): string
    {
        return ltrim($canonical, '-');
    }

    /**
     * Exact three-way comparison of two canonical integers: sign, then length, then bytes.
     */
    public static function compare(string $a, string $b): int
    {
        $signA = self::sign($a);
        $signB = self::sign($b);

        if ($signA !== $signB) {
            return $signA <=> $signB;
        }

        if ($signA === 0) {
            return 0;
        }

        $magnitude = self::compareMagnitude(ltrim($a, '-'), ltrim($b, '-'));

        return $signA < 0 ? -$magnitude : $magnitude;
    }

    public static function fitsInt(string $canonical): bool
    {
        $magnitude = ltrim($canonical, '-');
        $limit = self::isNegative($canonical) ? self::INT_MIN_ABS : self::INT_MAX;

        return self::compareMagnitude($magnitude, $limit) <= 0;
    }

    /**
     * The one place a canonical integer becomes a PHP int — after the range check.
     */
    public static function toInt(string $canonical): int
    {
        if (! self::fitsInt($canonical)) {
            throw AmountOverflow::int64($canonical);
        }

        if ($canonical === '-'.self::INT_MIN_ABS) {
            return PHP_INT_MIN;
        }

        return (int) $canonical;
    }

    /**
     * Render a canonical integer at `$scale` decimal places: pad to `scale + 1` digits and
     * insert the point. Pure string slicing.
     */
    public static function toDecimal(string $canonical, int $scale, bool $trimTrailingZeros = false): string
    {
        $negative = self::isNegative($canonical);
        $digits = ltrim($canonical, '-');

        if ($scale === 0) {
            return $canonical;
        }

        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $integer = substr($digits, 0, -$scale);
        $fraction = substr($digits, -$scale);

        if ($trimTrailingZeros) {
            $fraction = rtrim($fraction, '0');
        }

        $rendered = $fraction === '' ? $integer : $integer.'.'.$fraction;

        return $negative ? '-'.$rendered : $rendered;
    }

    /**
     * Guard a computed canonical result against the digit cap.
     */
    public static function capped(string $canonical, string $operation, int $maxDigits = self::MAX_DIGITS): string
    {
        if (self::digits($canonical) > $maxDigits) {
            throw AmountOverflow::digits($operation, $maxDigits);
        }

        return $canonical;
    }

    private static function compareMagnitude(string $a, string $b): int
    {
        $length = strlen($a) <=> strlen($b);

        if ($length !== 0) {
            return $length;
        }

        return strcmp($a, $b) <=> 0;
    }
}
