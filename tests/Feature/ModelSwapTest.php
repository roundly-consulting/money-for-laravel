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
