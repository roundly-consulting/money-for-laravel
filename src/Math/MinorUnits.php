<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Math;

use RoundingMode;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;

/**
 * Currency-less helpers for integer amounts at a decimal scale — the rescale/rounding core
 * other packages (credits' formatting, provider SDK amounts) use without building a Money.
 *
 * Every amount goes in as `int|string` and comes out as a canonical integer string; the
 * only `int` out is the explicit, throwing {@see self::toInt()} bridge.
 */
final class MinorUnits
{
    public const int MAX_SCALE = 36;

    /** "007" → "7", "-0" → "0"; at most 65 significant digits. */
    public static function normalize(int|string $minor): string
    {
        return IntegerString::normalize($minor);
    }

    /** (1050, 2) → "10.50"; with `$trimTrailingZeros` → "10.5". */
    public static function toDecimal(int|string $minor, int $scale, bool $trimTrailingZeros = false): string
    {
        self::assertScale($scale);

        return IntegerString::toDecimal(IntegerString::normalize($minor), $scale, $trimTrailingZeros);
    }

    /** ("10.5", 2) → "1050"; excess precision needs a rounding mode. */
    public static function fromDecimal(string $decimal, int $scale, ?RoundingMode $rounding = null): string
    {
        self::assertScale($scale);

        return IntegerString::capped(DecimalString::toScaledInteger($decimal, $scale, $rounding), 'fromDecimal');
    }

    /** Move an integer amount from one decimal scale to another, rounding once when scaling down. */
    public static function rescale(int|string $minor, int $fromScale, int $toScale, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): string
    {
        self::assertScale($fromScale);
        self::assertScale($toScale);

        $minor = IntegerString::normalize($minor);

        if ($toScale >= $fromScale) {
            $scaled = $minor === '0' ? '0' : $minor.str_repeat('0', $toScale - $fromScale);

            return IntegerString::capped($scaled, 'rescale');
        }

        return Rounder::divide($minor, Calculator::pow10($fromScale - $toScale), $rounding);
    }

    /** The explicit int bridge: throws AmountOverflow::int64 outside the 64-bit range. */
    public static function toInt(int|string $minor): int
    {
        return IntegerString::toInt(IntegerString::normalize($minor));
    }

    private static function assertScale(int $scale): void
    {
        if ($scale < 0 || $scale > self::MAX_SCALE) {
            throw InvalidAmount::scale($scale, self::MAX_SCALE);
        }
    }
}
