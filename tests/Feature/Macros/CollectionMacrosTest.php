<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\EmptyMoneyCollection;
use RoundlyConsulting\Money\Money;

it('aggregates money on collections and lazy collections', function (Closure $make): void {
    $items = $make([
        ['price' => Money::ofMinor(100, 'EUR'), 'qty' => 2],
        ['price' => Money::ofMinor(250, 'EUR'), 'qty' => 1],
        ['price' => Money::ofMinor(51, 'EUR'), 'qty' => 3],
    ]);

    expect($items->sumMoney('price')->minor())->toBe('401')
        ->and($items->sumMoney(fn (array $item): Money => $item['price']->multiply($item['qty']))->minor())->toBe('603')
        ->and($items->minMoney('price')->minor())->toBe('51')
        ->and($items->maxMoney('price')->minor())->toBe('250')
        ->and($items->avgMoney('price')->minor())->toBe('134')
        ->and($items->avgMoney('price', RoundingMode::TowardsZero)->minor())->toBe('133')
        ->and($items->pluck('price')->sumMoney()->minor())->toBe('401');
})->with([
    'collection' => [fn (array $items) => new Collection($items)],
    'lazy' => [fn (array $items) => LazyCollection::make($items)],
]);

it('reads the avgMoney default rounding from config', function (): void {
    config(['money.rounding' => 'towards_zero']);

    expect(collect([Money::ofMinor(1, 'EUR'), Money::ofMinor(2, 'EUR')])->avgMoney()->minor())->toBe('1');
});

it('handles empty collections and mixed currencies', function (): void {
    expect(collect()->sumMoney(currencyIfEmpty: 'EUR')->isZero())->toBeTrue()
        ->and(fn () => collect()->sumMoney())->toThrow(EmptyMoneyCollection::class)
        ->and(fn () => collect()->minMoney())->toThrow(EmptyMoneyCollection::class)
        ->and(fn () => collect([Money::ofMinor(1, 'EUR'), Money::ofMinor(1, 'USD')])->sumMoney())->toThrow(CurrencyMismatch::class);
});
