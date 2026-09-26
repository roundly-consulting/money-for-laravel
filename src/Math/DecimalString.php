<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Math;

use RoundingMode;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Exceptions\RoundingNecessary;

/**
 * The canonical decimal grammar (`^[+-]?\d+(\.\d+)?$`, ASCII digits, no grouping, no
 * exponent) and the single, allow-listed float boundary ({@see self::fromFloat()}).
 *
 * The canonical form strips `+`, leading integer zeros and trailing fraction zeros, and
 * never renders a negative zero: `"+010.500"` → `"10.5"`, `"-0.00"` → `"0"`.
 *
 * @internal
 */
final class DecimalString
{
    /**
     * Digits accepted in one decimal: 65 minor digits + 18 fraction digits + slack, so an
     * over-precise input is still *recognised* (to raise RoundingNecessary) while bcmath
     * cost stays bounded.
     */
    public const int MAX_DIGITS = 90;

    /** IEEE doubles are exact integers only below 2^53. */
    private const float FLOAT_LIMIT = 9007199254740992.0;

    public static function normalize(int|string $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (strlen($value) > self::MAX_DIGITS + 2) {
            throw InvalidAmount::tooLong(strlen($value), self::MAX_DIGITS);
        }

        if (preg_match('/\A([+-]?)([0-9]+)(?:\.([0-9]+))?\z/', $value, $matches) !== 1) {
            throw InvalidAmount::notDecimal($value);
        }

        $integer = ltrim($matches[2], '0');
        $fraction = rtrim($matches[3] ?? '', '0');

        if (strlen($matches[2]) + strlen($matches[3] ?? '') > self::MAX_DIGITS) {
            throw InvalidAmount::tooLong(strlen($matches[2]) + strlen($matches[3] ?? ''), self::MAX_DIGITS);
        }

        if ($integer === '' && $fraction === '') {
            return '0';
        }

        $rendered = ($integer === '' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);

        return $matches[1] === '-' ? '-'.$rendered : $rendered;
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/\A[+-]?[0-9]+(\.[0-9]+)?\z/', $value) === 1;
    }

    /** Fraction digits of a canonical decimal. */
    public static function scale(string $canonical): int
    {
        $point = strpos($canonical, '.');

        return $point === false ? 0 : strlen($canonical) - $point - 1;
    }

    /** The canonical decimal with its point removed: "-12.34" → "-1234". */
    public static function unscaled(string $canonical): string
    {
        return IntegerString::normalize(str_replace('.', '', $canonical), self::MAX_DIGITS);
    }

    /**
     * The decimal as an integer at `$scale` decimal places — exact, or rounded once when a
     * rounding mode is given; excess precision without one is refused.
     */
    public static function toScaledInteger(int|string $decimal, int $scale, ?RoundingMode $rounding = null): string
    {
        $canonical = self::normalize($decimal);
        $excess = self::scale($canonical) - $scale;
        $unscaled = self::unscaled($canonical);

        if ($excess <= 0) {
            return $unscaled === '0' ? '0' : $unscaled.str_repeat('0', -$excess);
        }

        if ($rounding === null) {
            throw RoundingNecessary::for($canonical, $scale);
        }

        return Rounder::divide($unscaled, Calculator::pow10($excess), $rounding);
    }

    /**
     * The shortest decimal that round-trips to `$value`, independent of the host's
     * `precision` / `serialize_precision` INI (a host at 17 would render 1.1 as
     * 1.1000000000000001). The only place a float becomes a decimal.
     */
    public static function fromFloat(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            throw InvalidAmount::float('the value is not finite');
        }

        if (abs($value) >= self::FLOAT_LIMIT) {
            throw InvalidAmount::float('it is at or beyond 2^53, where doubles no longer hold every integer');
        }

        $scientific = sprintf('%.16e', $value);

        for ($precision = 1; $precision <= 17; $precision++) {
            $candidate = sprintf('%.'.($precision - 1).'e', $value);

            if ((float) $candidate === $value) {
                $scientific = $candidate;
                break;
            }
        }

        return self::expandScientific($scientific);
    }

    /**
     * "1.25e-3" → "0.00125", by string manipulation only.
     */
    private static function expandScientific(string $scientific): string
    {
        if (preg_match('/\A(-?)([0-9])(?:[.,]([0-9]+))?e([+-][0-9]+)\z/', $scientific, $matches) !== 1) {
            throw InvalidAmount::float("unexpected rendering [{$scientific}]");
        }

        $digits = $matches[2].$matches[3];
        $point = 1 + IntegerString::toInt(IntegerString::normalize(ltrim($matches[4], '+')));

        $plain = match (true) {
            $point <= 0 => '0.'.str_repeat('0', -$point).$digits,
            $point >= strlen($digits) => $digits.str_repeat('0', $point - strlen($digits)),
            default => substr($digits, 0, $point).'.'.substr($digits, $point),
        };

        return self::normalize($matches[1].$plain);
    }
}
