<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A rate date is a calendar day: the `Y-m-d` of the requested date in its OWN timezone —
 * exactly how a rate is written. Converting the instant into the exchange timezone first
 * turned `Carbon::parse('2026-01-15')` into the 14th for every app east of it.
 *
 * @internal
 */
final class RateDate
{
    /** Midnight of `$on`'s calendar day, in the exchange timezone. */
    public static function of(CarbonInterface $on, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse($on->format('Y-m-d'), $timezone);
    }

    /** Whether `$on`'s calendar day has not begun yet where the caller is. */
    public static function isFuture(CarbonInterface $on): bool
    {
        return $on->format('Y-m-d') > CarbonImmutable::now($on->getTimezone())->format('Y-m-d');
    }
}
