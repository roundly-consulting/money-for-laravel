<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\InvalidCurrency;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exceptions\UnknownCurrency;
use RoundlyConsulting\Money\Facades\Currencies;

it('resolves registered currencies through the registry', function (): void {
    $eur = Currency::of(' eur ');

    expect($eur->code)->toBe('EUR')
        ->and($eur->exponent)->toBe(2)
        ->and($eur->name)->toBe('Euro')
        ->and($eur->symbol)->toBe('€')
        ->and($eur->numericCode)->toBe(978)
        ->and($eur->iso)->toBeTrue()
        ->and((string) $eur)->toBe('EUR')
        ->and(json_encode($eur))->toBe('"EUR"')
        ->and($eur->displaySymbol())->toBe('€')
        ->and($eur->minorPerMajor())->toBe('100')
        ->and(Currency::of('BHD')->numericCodeString())->toBe('048');
});

it('throws for an unknown code', function (): void {
    Currency::of('XYZ');
})->throws(UnknownCurrency::class);

it('builds custom currencies', function (): void {
    $pts = Currency::custom('pts', 0);
    $eth = Currency::custom('ETH', 18, 'Ether', 'Ξ');

    expect($pts->code)->toBe('PTS')
        ->and($pts->name)->toBe('PTS')
        ->and($pts->symbol)->toBeNull()
        ->and($pts->displaySymbol())->toBe('PTS')
        ->and($pts->numericCode)->toBeNull()
        ->and($pts->numericCodeString())->toBeNull()
        ->and($pts->iso)->toBeFalse()
        ->and($eth->minorPerMajor())->toBe('1000000000000000000')
        ->and(Currency::custom('USDT', 6)->code)->toBe('USDT')
        ->and(Currency::custom('CREDIT', 2)->code)->toBe('CREDIT');
});

it('treats code and exponent as identity', function (): void {
    expect(Currency::custom('PTS', 0)->equals(Currency::custom('PTS', 0, 'Points')))->toBeTrue()
        ->and(Currency::custom('PTS', 0)->equals(Currency::custom('PTS', 2)))->toBeFalse()
        ->and(Currency::of('EUR')->equals(new Currency('EUR', 3, 'Euro')))->toBeFalse();
});

it('validates codes, exponents, numerics, names and symbols', function (Closure $build): void {
    $build();
})->throws(InvalidCurrency::class)->with([
    'iso lower-case' => [fn () => new Currency('EU', 2, 'x', numericCode: 1, iso: true)],
    'iso with digit' => [fn () => new Currency('EU1', 2, 'x', numericCode: 1, iso: true)],
    'custom too short' => [fn () => Currency::custom('P', 0)],
    'custom too long' => [fn () => Currency::custom('ABCDEFGHIJK', 0)],
    'custom starts with digit' => [fn () => Currency::custom('1AB', 0)],
    'custom punctuation' => [fn () => Currency::custom('A-B', 0)],
    'iso exponent 5' => [fn () => new Currency('ABC', 5, 'x', numericCode: 1, iso: true)],
    'custom exponent 19' => [fn () => Currency::custom('ETH', 19)],
    'negative exponent' => [fn () => Currency::custom('ETH', -1)],
    'iso without numeric' => [fn () => new Currency('ABC', 2, 'x', iso: true)],
    'iso numeric 1000' => [fn () => new Currency('ABC', 2, 'x', numericCode: 1000, iso: true)],
    'empty name' => [fn () => new Currency('ABC', 2, ' ')],
    'long symbol' => [fn () => Currency::custom('ABC', 2, 'x', '123456789')],
    'empty symbol' => [fn () => Currency::custom('ABC', 2, 'x', '')],
]);

it('reports the largest storable major amount per column precision', function (): void {
    expect(Currency::of('EUR')->maxMajorAmount(38))->toBe(str_repeat('9', 36).'.99')
        ->and(Currency::of('JPY')->maxMajorAmount(19))->toBe(str_repeat('9', 19))
        ->and(Currency::custom('ETH', 18)->maxMajorAmount(38))->toBe(str_repeat('9', 20).'.'.str_repeat('9', 18));

    Currency::of('EUR')->maxMajorAmount(18);
})->throws(InvalidMoneyConfiguration::class);

it('is exposed through the Currencies facade', function (): void {
    Currencies::register(Currency::custom('PTS', 0, 'Loyalty points', 'pts'));

    expect(Currencies::get('EUR')->exponent)->toBe(2)
        ->and(Currencies::custom())->toHaveCount(1)
        ->and(Currencies::custom()[0]->code)->toBe('PTS');
});
