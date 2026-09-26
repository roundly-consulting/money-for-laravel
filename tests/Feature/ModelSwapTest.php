<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Money\Actions\StoreExchangeRatesAction;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Exchange\Providers\DatabaseExchangeRateProvider;
use RoundlyConsulting\Money\Models\CurrencyRate;
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
