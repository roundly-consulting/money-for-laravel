<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User;
use RoundlyConsulting\Money\Actions\StoreExchangeRatesAction;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Exchange\Providers\DatabaseExchangeRateProvider;
use RoundlyConsulting\Money\Models\CurrencyRate;
use RoundlyConsulting\Money\Support\CurrencyRateModel;
use RoundlyConsulting\Money\Tests\Fixtures\Models\CustomCurrencyRate;

it('declares the rate model swappable', function (): void {
    expect(CurrencyRate::class)->toBeSwappableVia('money.exchange.providers.database.model');
});

it('reads rates through the swapped model', function (): void {
    config(['money.exchange.providers.database.model' => CustomCurrencyRate::class]);

    app(StoreExchangeRatesAction::class)->execute([
        ExchangeRate::fromDecimal('EUR', 'USD', '1.0854', CarbonImmutable::parse('2026-09-25')),
    ]);

    expect('money.exchange.providers.database.model')->toHonourModelSwap(
        CustomCurrencyRate::class,
        fn () => (new DatabaseExchangeRateProvider)->row('EUR', 'USD', CarbonImmutable::parse('2026-09-25')),
        expectsCreation: false,
    );
});

/**
 * The factory named the packaged CurrencyRate, so `CurrencyRate::factory()` built the packaged
 * class after a host swapped it: the host's model events never ran.
 */
it('builds the swapped rate model from the factory', function (): void {
    config(['money.exchange.providers.database.model' => CustomCurrencyRate::class]);
    CustomCurrencyRate::resetCreationCount();

    // Distinct pairs: the table is unique per pair and date.
    expect(CurrencyRate::factory()->pair('EUR', 'USD', '1.0854')->create()::class)->toBe(CustomCurrencyRate::class)
        ->and(CustomCurrencyRate::factory()->pair('EUR', 'GBP', '0.8612')->create()::class)->toBe(CustomCurrencyRate::class)
        ->and(CustomCurrencyRate::creationCount())->toBe(2);
});

it('refuses a rate model that does not extend CurrencyRate with the package exception', function (string $configured): void {
    config(['money.exchange.providers.database.model' => $configured]);

    expect(fn (): string => CurrencyRateModel::class())->toThrow(
        InvalidMoneyConfiguration::class,
        '[money.exchange.providers.database.model] is misconfigured: must extend '.CurrencyRate::class.", [{$configured}] given.",
    );
})->with([
    'an unrelated model' => [User::class],
    'a non-model class' => [stdClass::class],
    'a missing class' => ['App\\Models\\Missing'],
]);
