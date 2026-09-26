<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Support;

use Illuminate\Support\Str;
use RoundingMode;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;

/**
 * Config strings ⇄ PHP 8.4's native `\RoundingMode`. The config vocabulary is the snake_case
 * of the case names (`half_even`, `half_away_from_zero`, …); ambiguous aliases such as
 * `half_up` are refused rather than guessed.
 *
 * Consumers call {@see self::fromValue()} with their own literal `config('pkg.rounding')`
 * read, so the config-contract scraper still sees the key as read.
 */
final class RoundingModes
{
    public static function fromValue(mixed $value, string $key): RoundingMode
    {
        if ($value instanceof RoundingMode) {
            return $value;
        }

        if (is_string($value)) {
            foreach (RoundingMode::cases() as $mode) {
                if (self::toValue($mode) === $value) {
                    return $mode;
                }
            }
        }

        throw InvalidMoneyConfiguration::rounding($key, $value);
    }

    /** Money-internal convenience: `fromValue(config($key), $key)`. */
    public static function fromConfig(string $key): RoundingMode
    {
        return self::fromValue(config($key), $key);
    }

    public static function toValue(RoundingMode $mode): string
    {
        return Str::snake($mode->name);
    }
}
