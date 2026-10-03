<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Support;

use RoundingMode;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\PackageToolkit\Support\ConfigValidator;

/**
 * Strict reads of the non-boolean `money.*` settings. A key that is not set — absent, null or
 * blank (`''` or whitespace, a host's `KEY=`) — takes its documented default; a present value
 * of the wrong shape — `'five'` for a timeout, an array for a table name — throws
 * {@see InvalidMoneyConfiguration} naming the key. Junk is never cast or silently replaced by
 * the default.
 *
 * @internal
 */
final class MoneyConfig
{
    public const string ECB_DAILY_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    public const string ECB_RECENT_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml';

    public const string ECB_HISTORY_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml';

    public static function defaultCurrency(): string
    {
        return self::string('money.default_currency', 'EUR');
    }

    /** The service-level rounding mode (formatter digit reduction, `avgMoney`). */
    public static function rounding(): RoundingMode
    {
        return RoundingModes::fromConfig('money.rounding', RoundingMode::HalfAwayFromZero);
    }

    /** The default rounding of conversions. */
    public static function exchangeRounding(): RoundingMode
    {
        return RoundingModes::fromConfig('money.exchange.rounding', RoundingMode::HalfEven);
    }

    /** The timezone of "today" for undated lookups and manual rates. */
    public static function timezone(): string
    {
        return self::string('money.exchange.timezone', 'Europe/Berlin');
    }

    public static function exchangeDriver(): string
    {
        return self::string('money.exchange.default', 'ecb');
    }

    /** The pivot currency for cross rates, upper-cased; null when none is configured. */
    public static function pivot(): ?string
    {
        $pivot = self::optionalString('money.exchange.pivot');

        return $pivot === null ? null : strtoupper($pivot);
    }

    /**
     * The drivers the `chain` driver tries, in order.
     *
     * @return list<string>
     */
    public static function chain(): array
    {
        $names = self::read('money.exchange.chain');

        if ($names === null) {
            return [];
        }

        if (! is_array($names) || ! array_is_list($names)) {
            throw InvalidMoneyConfiguration::invalid('money.exchange.chain', 'expected a list of driver names');
        }

        foreach ($names as $name) {
            if (! is_string($name) || trim($name) === '') {
                throw InvalidMoneyConfiguration::invalid('money.exchange.chain', 'every entry must be a driver name');
            }

            if ($name === 'chain') {
                throw InvalidMoneyConfiguration::invalid('money.exchange.chain', 'the chain cannot contain itself');
            }
        }

        return $names;
    }

    /**
     * The static rates of the `config` driver.
     *
     * @return array<array-key, mixed>
     */
    public static function configRates(): array
    {
        return self::array('money.exchange.providers.config.rates');
    }

    /** Days a newest rate may age before it is stale; 0 accepts only the day's own rate. */
    public static function maxAgeDays(): int
    {
        return self::integer('money.exchange.max_age_days', 7, min: 0);
    }

    public static function cacheStore(): ?string
    {
        return self::optionalString('money.exchange.cache.store');
    }

    public static function cachePrefix(): string
    {
        return self::string('money.exchange.cache.prefix', 'money:exchange');
    }

    /** Seconds a cached lookup lives; at least 1 (switch caching off with `cache.enabled`). */
    public static function cacheTtl(): int
    {
        return self::integer('money.exchange.cache.ttl', 3600, min: 1);
    }

    public static function table(): string
    {
        return self::string('money.exchange.providers.database.table', 'money_exchange_rates');
    }

    /**
     * The ECB feed URLs, by feed value.
     *
     * @return array{daily: string, recent: string, history: string}
     */
    public static function ecbUrls(): array
    {
        return [
            'daily' => self::string('money.exchange.providers.ecb.daily_url', self::ECB_DAILY_URL),
            'recent' => self::string('money.exchange.providers.ecb.recent_url', self::ECB_RECENT_URL),
            'history' => self::string('money.exchange.providers.ecb.history_url', self::ECB_HISTORY_URL),
        ];
    }

    public static function ecbTimeout(): int
    {
        return self::integer('money.exchange.providers.ecb.timeout', 10, min: 1);
    }

    public static function ecbRetries(): int
    {
        return self::integer('money.exchange.providers.ecb.retries', 2, min: 0);
    }

    public static function ecbMaxBytes(): int
    {
        return self::integer('money.exchange.providers.ecb.max_bytes', 33_554_432, min: 1);
    }

    public static function ecbCacheTtl(): int
    {
        return self::integer('money.exchange.providers.ecb.cache_ttl', 3600, min: 1);
    }

    public static function refreshSource(): string
    {
        return self::string('money.exchange.refresh.source', 'ecb');
    }

    public static function refreshCron(): string
    {
        return self::string('money.exchange.refresh.cron', '30 16 * * 1-5');
    }

    public static function refreshTimezone(): string
    {
        return self::string('money.exchange.refresh.timezone', 'Europe/Berlin');
    }

    /** The formatting locale; null follows the app locale. */
    public static function locale(): ?string
    {
        return self::optionalString('money.formatting.locale');
    }

    public static function fallbackPattern(): string
    {
        return self::string('money.formatting.fallback.pattern', '{sign}{amount} {code}');
    }

    /** Not set (absent, null or blank) means `.`; anything but a string throws. */
    public static function decimalSeparator(): string
    {
        $separator = self::read('money.formatting.fallback.decimal_separator') ?? '.';

        if (! is_string($separator)) {
            throw InvalidMoneyConfiguration::invalid('money.formatting.fallback.decimal_separator', 'expected a string');
        }

        return $separator;
    }

    /**
     * The one setting where blank is a value, not "not set": `''` means no grouping symbol and
     * `' '` groups with a space (`1 234.50`). Only absent or null takes the default `,`; anything
     * but a string throws.
     */
    public static function thousandsSeparator(): string
    {
        $separator = config('money.formatting.fallback.thousands_separator') ?? ',';

        if (! is_string($separator)) {
            throw InvalidMoneyConfiguration::invalid('money.formatting.fallback.thousands_separator', 'expected a string');
        }

        return $separator;
    }

    /**
     * The custom currency definitions, keyed by code.
     *
     * @return array<array-key, mixed>
     */
    public static function customCurrencies(): array
    {
        return self::array('money.currencies.custom');
    }

    /**
     * The input allow-list, upper-cased; null (no key) means the whole registry.
     *
     * @return list<string>|null
     */
    public static function allowedCurrencies(): ?array
    {
        $allowed = self::read('money.currencies.allowed');

        return $allowed === null ? null : self::currencyCodes('money.currencies.allowed', $allowed);
    }

    /**
     * A list of currency codes, trimmed and upper-cased; anything that is not a list of
     * non-blank strings throws naming `$key`.
     *
     * @return list<string>
     */
    public static function currencyCodes(string $key, mixed $codes): array
    {
        if (! is_array($codes)) {
            throw InvalidMoneyConfiguration::invalid($key, 'expected a list of currency codes');
        }

        $normalized = [];

        foreach ($codes as $code) {
            if (! is_string($code) || trim($code) === '') {
                throw InvalidMoneyConfiguration::invalid($key, 'every entry must be a currency code string');
            }

            $normalized[] = strtoupper(trim($code));
        }

        return $normalized;
    }

    /** An integer setting: `$default` when not set; anything but a canonical integer in range throws. */
    public static function integer(string $key, int $default, ?int $min = null, ?int $max = null): int
    {
        return self::validator()->integer($key, $default, $min, $max);
    }

    /** A string setting: `$default` when not set (absent, null or blank); a non-string throws. */
    public static function string(string $key, string $default): string
    {
        return self::read($key) === null ? $default : self::validator()->requireString($key);
    }

    /** An optional string setting: null when not set (absent, null or blank); a non-string throws. */
    public static function optionalString(string $key): ?string
    {
        return self::read($key) === null ? null : self::validator()->requireString($key);
    }

    /**
     * An array setting: empty when not set; anything but an array throws.
     *
     * @return array<array-key, mixed>
     */
    private static function array(string $key): array
    {
        $value = self::read($key) ?? [];

        if (! is_array($value)) {
            throw InvalidMoneyConfiguration::invalid($key, 'expected an array');
        }

        return $value;
    }

    /** The raw value at `$key`, a blank string (`''` or whitespace — a host's `KEY=`) read as null. */
    private static function read(string $key): mixed
    {
        $value = config($key);

        return is_string($value) && trim($value) === '' ? null : $value;
    }

    private static function validator(): ConfigValidator
    {
        return Config::using(InvalidMoneyConfiguration::class);
    }
}
