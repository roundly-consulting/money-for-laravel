<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Formatting;

use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Support\MoneyConfig;

/**
 * Resolves and validates the locale for formatting and parsing: explicit →
 * money.formatting.locale → the app locale. A garbage locale would otherwise silently fall
 * back to ICU's root locale.
 *
 * @internal
 */
final class Locales
{
    public static function resolve(?string $locale): string
    {
        $locale ??= MoneyConfig::locale() ?? app()->getLocale();

        if (preg_match('/\A[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*\z/', $locale) !== 1) {
            throw InvalidMoneyConfiguration::locale($locale);
        }

        return $locale;
    }
}
