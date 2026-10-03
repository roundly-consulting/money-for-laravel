<?php

declare(strict_types=1);

/**
 * The config contract, pinned in both directions: every key the code reads is shipped,
 * every shipped leaf is read. The package's own readers — Support\MoneyConfig and
 * RoundingModes::fromConfig() — take the key as an argument the token scraper does not
 * follow into the reader, so each key read only that way is named exactly here (never a
 * blanket `money.` prefix). The toolkit's own readers need no entry: the contract reads
 * them natively.
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../config/money.php')->toSatisfyConfigContract(__DIR__.'/../src', [
        'extraReadPrefixes' => [
            'money.rounding',
            'money.exchange.rounding',
            // Support\MoneyConfig's strict readers (integer, string, list) — each key
            // appears there as a literal handed to the validator.
            'money.currencies.allowed',
            'money.currencies.custom',
            'money.default_currency',
            'money.exchange.cache.prefix',
            'money.exchange.cache.store',
            'money.exchange.cache.ttl',
            'money.exchange.chain',
            'money.exchange.default',
            'money.exchange.max_age_days',
            'money.exchange.pivot',
            'money.exchange.providers.config.rates',
            'money.exchange.providers.database.table',
            'money.exchange.providers.ecb.cache_ttl',
            'money.exchange.providers.ecb.daily_url',
            'money.exchange.providers.ecb.history_url',
            'money.exchange.providers.ecb.max_bytes',
            'money.exchange.providers.ecb.recent_url',
            'money.exchange.providers.ecb.retries',
            'money.exchange.providers.ecb.timeout',
            'money.exchange.refresh.cron',
            'money.exchange.refresh.source',
            'money.exchange.refresh.timezone',
            'money.exchange.timezone',
            'money.formatting.fallback.decimal_separator',
            'money.formatting.fallback.pattern',
            'money.formatting.locale',
            'money.schema.currency_length',
            'money.schema.precision',
        ],
    ]);
});
