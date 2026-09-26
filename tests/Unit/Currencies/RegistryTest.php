<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Currencies\DefaultCurrencyRegistry;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\CurrencyAlreadyRegistered;
use RoundlyConsulting\Money\Exceptions\InvalidCurrency;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exceptions\UnknownCurrency;

it('is bound as a singleton seeded with ISO 4217', function (): void {
    $registry = app(CurrencyRegistry::class);

    expect($registry)->toBe(app(CurrencyRegistry::class))
        ->and($registry)->toBeInstanceOf(DefaultCurrencyRegistry::class)
        ->and($registry->iso())->toHaveCount(165)
        ->and($registry->custom())->toBe([])
        ->and($registry->has('eur'))->toBeTrue()
        ->and($registry->find('nope'))->toBeNull();
});

it('looks up by numeric code', function (): void {
    $registry = app(CurrencyRegistry::class);

    expect($registry->findByNumericCode(978)?->code)->toBe('EUR')
        ->and($registry->findByNumericCode('048')?->code)->toBe('BHD')
        ->and($registry->findByNumericCode('abc'))->toBeNull()
        ->and($registry->findByNumericCode(1))->toBeNull();
});

it('lists currencies sorted by code', function (): void {
    $registry = new DefaultCurrencyRegistry([Currency::custom('ZZZ', 0), Currency::custom('AAA', 0)]);

    expect(array_map(static fn (Currency $currency): string => $currency->code, $registry->all()))->toBe(['AAA', 'ZZZ']);
});

it('refuses duplicates unless replacing a custom currency', function (): void {
    $registry = new DefaultCurrencyRegistry([Currency::custom('PTS', 0)]);

    $registry->register(Currency::custom('PTS', 2), replace: true);

    expect($registry->get('PTS')->exponent)->toBe(2)
        ->and(fn () => $registry->register(Currency::custom('PTS', 0)))->toThrow(CurrencyAlreadyRegistered::class);
});

it('never lets an ISO currency be redefined', function (): void {
    app(CurrencyRegistry::class)->register(Currency::custom('EUR', 3), replace: true);
})->throws(CurrencyAlreadyRegistered::class);

it('refuses custom codes longer than the currency column', function (): void {
    expect(fn () => app(CurrencyRegistry::class)->register(Currency::custom('USDT', 6)))
        ->toThrow(InvalidCurrency::class, 'currency_length');

    $wide = new DefaultCurrencyRegistry(maxCodeLength: 10);
    $wide->register(Currency::custom('USDT', 6));

    expect($wide->has('USDT'))->toBeTrue();
});

it('throws UnknownCurrency with the normalised code', function (): void {
    app(CurrencyRegistry::class)->get(' xyz ');
})->throws(UnknownCurrency::class, 'XYZ');

it('builds from config: custom currencies and an ISO-free registry', function (): void {
    config([
        'money.currencies.iso' => false,
        'money.schema.currency_length' => 10,
        'money.currencies.custom' => [
            'PTS' => ['exponent' => 0, 'name' => 'Points', 'symbol' => 'pts'],
            'ETH' => ['exponent' => 18, 'symbol' => 'Ξ'],
            'CREDIT' => ['exponent' => 2],
        ],
    ]);

    $registry = DefaultCurrencyRegistry::fromConfig();

    expect($registry->iso())->toBe([])
        ->and(array_map(static fn (Currency $currency): string => $currency->code, $registry->all()))->toBe(['CREDIT', 'ETH', 'PTS'])
        ->and($registry->get('PTS')->name)->toBe('Points')
        ->and($registry->get('ETH')->exponent)->toBe(18)
        ->and($registry->get('ETH')->name)->toBe('ETH');
});

it('fails loud on malformed custom currency config', function (mixed $definition): void {
    config(['money.currencies.custom' => ['PTS' => $definition]]);

    DefaultCurrencyRegistry::fromConfig();
})->throws(InvalidMoneyConfiguration::class)->with([
    'not an array' => ['points'],
    'exponent out of range' => [['exponent' => 19]],
    'exponent not int' => [['exponent' => 'two']],
    'name not string' => [['exponent' => 0, 'name' => 5]],
]);

it('fails loud on an out-of-range currency length', function (): void {
    config(['money.schema.currency_length' => 2]);

    DefaultCurrencyRegistry::fromConfig();
})->throws(InvalidMoneyConfiguration::class);
