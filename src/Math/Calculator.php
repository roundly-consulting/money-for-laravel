<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Math;

use RoundlyConsulting\Money\Exceptions\DivisionByZero;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;

/**
 * Integer-string arithmetic on canonical operands. Small operands take a native-int fast
 * path (it cannot overflow: |a|, |b| < 10^18 ⇒ |a ± b| < 2·10^18 < PHP_INT_MAX), everything
 * else goes through bcmath at scale 0. Results are canonical but **uncapped** — the caller
 * decides which digit cap applies ({@see IntegerString::capped()}).
 *
 * @internal
 */
final class Calculator
{
    private const int FAST_DIGITS = 18;

    public static function add(string $a, string $b): string
    {
        if (IntegerString::digits($a) <= self::FAST_DIGITS && IntegerString::digits($b) <= self::FAST_DIGITS) {
            return (string) (IntegerString::toInt($a) + IntegerString::toInt($b));
        }

        return self::canonical(bcadd(self::operand($a), self::operand($b), 0));
    }

    public static function sub(string $a, string $b): string
    {
        if (IntegerString::digits($a) <= self::FAST_DIGITS && IntegerString::digits($b) <= self::FAST_DIGITS) {
            return (string) (IntegerString::toInt($a) - IntegerString::toInt($b));
        }

        return self::canonical(bcsub(self::operand($a), self::operand($b), 0));
    }

    public static function mul(string $a, string $b): string
    {
        if (IntegerString::digits($a) + IntegerString::digits($b) <= self::FAST_DIGITS) {
            return (string) (IntegerString::toInt($a) * IntegerString::toInt($b));
        }

        return self::canonical(bcmul(self::operand($a), self::operand($b), 0));
    }

    /**
     * Truncating integer division (quotient towards zero; remainder takes the dividend's sign).
     *
     * @return list<string> [quotient, remainder]
     */
    public static function divmod(string $a, string $b): array
    {
        if ($b === '0') {
            throw DivisionByZero::create();
        }

        [$quotient, $remainder] = bcdivmod(self::operand($a), self::operand($b), 0);

        return [self::canonical($quotient), self::canonical($remainder)];
    }

    /** "1" followed by `$exponent` zeros. */
    public static function pow10(int $exponent): string
    {
        return '1'.str_repeat('0', $exponent);
    }

    /** Greatest common divisor of |a| and |b| (gcd(0, 0) = 0). */
    public static function gcd(string $a, string $b): string
    {
        $a = IntegerString::abs($a);
        $b = IntegerString::abs($b);

        while ($b !== '0') {
            [, $remainder] = self::divmod($a, $b);
            $a = $b;
            $b = $remainder;
        }

        return $a;
    }

    /**
     * bcmath only takes numeric strings; every operand is canonical already, so this is a
     * guard, not a conversion.
     *
     * @return numeric-string
     */
    private static function operand(string $value): string
    {
        if (! is_numeric($value)) {
            throw InvalidAmount::notInteger($value);
        }

        return $value;
    }

    /** Re-canonicalise a bcmath result ("-0" → "0"). */
    private static function canonical(string $value): string
    {
        return IntegerString::normalize($value, PHP_INT_MAX);
    }
}
