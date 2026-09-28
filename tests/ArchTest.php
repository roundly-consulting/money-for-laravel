<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Exchange\ExchangeManager;
use RoundlyConsulting\Money\Exchange\RateStore;
use RoundlyConsulting\Money\Models\CurrencyRate;
use RoundlyConsulting\Testing\Arch\ArchPresets;

ArchPresets::strictTypes('RoundlyConsulting\Money');

// The swappable rate model is the one intentional extension point (MoneyException is
// abstract, which the preset already excludes). The manager and its rates() sub-accessor
// stay open only so Exchange::fake() can subtype them.
ArchPresets::finalByDefault('RoundlyConsulting\Money', [CurrencyRate::class, ExchangeManager::class, RateStore::class]);
ArchPresets::swappableModelsAreNotFinal([CurrencyRate::class => 'money.exchange.providers.database.model']);
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Money');
ArchPresets::modelsResolveThroughSeam(__DIR__.'/../src', 'Support', ['money.exchange.providers.database.model']);

// Not adopted: morphColumnsUseTheSeam — the rates migration has no polymorphic column, so
// the preset would be green forever (vacuous).

/**
 * The Dependency Policy as a test. No `alsoAllow`: money's `require` ships only
 * php/ext/illuminate/roundly. If this goes red the graph is wrong — never widen the
 * allow-list to quiet it.
 */
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../composer.json');

ArchPresets::noDebuggingLeftovers();

/**
 * Closure invariant: money sits in Tier 0 because its roundly closure is exactly the two
 * Tier-0 leaves. qr (T1) and auth (T2) are planned on that; any extra roundly require
 * re-tiers money and cascades through every consumer.
 */
it('requires exactly the two tier-0 leaves from roundly', function (): void {
    /** @var array{require: array<string, string>} $composer */
    $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    $roundly = array_values(array_filter(
        array_keys($composer['require']),
        static fn (string $package): bool => str_starts_with($package, 'roundly-consulting/'),
    ));

    sort($roundly);

    expect($roundly)->toBe([
        'roundly-consulting/enums-for-laravel',
        'roundly-consulting/package-toolkit-for-laravel',
    ]);
});
