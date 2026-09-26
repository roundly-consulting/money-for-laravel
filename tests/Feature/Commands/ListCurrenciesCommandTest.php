<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Facades\Currencies;

beforeEach(function (): void {
    config(['money.schema.currency_length' => 10]);
    app()->forgetInstance(CurrencyRegistry::class);
    Currencies::clearResolvedInstances();
    Currencies::register(Currency::custom('PTS', 0, 'Loyalty points', 'pts'));
});

it('lists every currency with its metadata', function (): void {
    $this->artisan('money:currencies', ['--code' => ['eur', 'PTS']])
        ->expectsTable(
            ['Code', 'Numeric', 'Exponent', 'Symbol', 'Name', 'Origin'],
            [
                ['EUR', '978', '2', '€', 'Euro', 'ISO 4217'],
                ['PTS', '—', '0', 'pts', 'Loyalty points', 'custom'],
            ],
        )
        ->assertSuccessful();
});

it('filters to custom currencies', function (): void {
    $this->artisan('money:currencies', ['--custom' => true])
        ->expectsTable(
            ['Code', 'Numeric', 'Exponent', 'Symbol', 'Name', 'Origin'],
            [['PTS', '—', '0', 'pts', 'Loyalty points', 'custom']],
        )
        ->assertSuccessful();
});

it('filters to ISO currencies', function (): void {
    $this->artisan('money:currencies', ['--iso' => true, '--code' => ['BHD']])
        ->expectsTable(
            ['Code', 'Numeric', 'Exponent', 'Symbol', 'Name', 'Origin'],
            [['BHD', '048', '3', 'BHD', 'Bahraini Dinar', 'ISO 4217']],
        )
        ->assertSuccessful();
});
