<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Support;

use Carbon\CarbonImmutable;

/**
 * A console date option: a real `Y-m-d` calendar date or null. Carbon alone rolls
 * `2026-02-30` over to March 2 and throws on `2026/02/03`.
 *
 * @internal
 */
final class DateOption
{
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** Whether the option was passed at all (an empty value counts as absent). */
    public static function given(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
