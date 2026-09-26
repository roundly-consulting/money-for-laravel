<?php

declare(strict_types=1);

/**
 * The config contract, pinned in both directions: every key the code reads is shipped,
 * every shipped leaf is read. Reads that are not a literal `config('money.…')` call —
 * the toolkit's Config::using() validators, RoundingModes::fromConfig() and
 * ModelResolver::for() — are invisible to the token scraper, so each one is named
 * exactly here (never a blanket `money.` prefix).
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../config/money.php')->toSatisfyConfigContract(__DIR__.'/../src', [
        'extraReadPrefixes' => [
            'money.schema.currency_length',
        ],
    ]);
});
