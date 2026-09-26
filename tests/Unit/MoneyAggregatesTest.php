<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\EmptyMoneyCollection;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyValue;
use RoundlyConsulting\Money\Money;

it('sums, finds min and max, and averages', function (): void {
    $monies = [Money::ofMinor(100, 'EUR'), Money::ofMinor(-50, 'EUR'), Money::ofMinor(251, 'EUR')];

    expect(Money::sum($monies)->minor())->toBe('301')
        ->and(Money::min($monies)->minor())->toBe('-50')
        ->and(Money::max($monies)->minor())->toBe('251')
        ->and(Money::average($monies)->minor())->toBe('100')
        ->and(Money::average([Money::ofMinor(1, 'EUR'), Money::ofMinor(2, 'EUR')])->minor())->toBe('2')
        ->and(Money::average([Money::ofMinor(1, 'EUR'), Money::ofMinor(2, 'EUR')], RoundingMode::HalfEven)->minor())->toBe('2')
        ->and(Money::average([Money::ofMinor(1, 'EUR'), Money::ofMinor(4, 'EUR')], RoundingMode::HalfEven)->minor())->toBe('2');
});

it('accepts any iterable', function (): void {
    $generator = (static function () {
        yield Money::ofMinor(1, 'EUR');
        yield Money::ofMinor(2, 'EUR');
    })();

    expect(Money::sum($generator)->minor())->toBe('3')
        ->and(Money::max(collect([Money::ofMinor(5, 'EUR')]))->minor())->toBe('5');
});

it('sums an empty collection only with a currency', function (): void {
    expect(Money::sum([], 'JPY')->equals(Money::zero('JPY')))->toBeTrue()
        ->and(Money::sum([Money::ofMinor(5, 'EUR')], 'JPY')->currency()->code)->toBe('EUR')
        ->and(fn () => Money::sum([]))->toThrow(EmptyMoneyCollection::class)
        ->and(fn () => Money::min([]))->toThrow(EmptyMoneyCollection::class)
        ->and(fn () => Money::max([]))->toThrow(EmptyMoneyCollection::class)
        ->and(fn () => Money::average([]))->toThrow(EmptyMoneyCollection::class);
});

it('refuses mixed currencies and non-money items', function (): void {
    expect(fn () => Money::min([Money::ofMinor(1, 'EUR'), Money::ofMinor(1, 'USD')]))->toThrow(CurrencyMismatch::class)
        ->and(fn () => Money::max([Money::ofMinor(1, 'EUR'), Money::ofMinor(1, 'USD')]))->toThrow(CurrencyMismatch::class)
        ->and(fn () => Money::average([Money::ofMinor(1, 'EUR'), Money::ofMinor(1, 'USD')]))->toThrow(CurrencyMismatch::class)
        ->and(fn () => Money::sum([Money::ofMinor(1, 'EUR'), 5]))->toThrow(InvalidMoneyValue::class)
        ->and(fn () => Money::min([5]))->toThrow(InvalidMoneyValue::class)
        ->and(fn () => Money::max([5]))->toThrow(InvalidMoneyValue::class)
        ->and(fn () => Money::average([5]))->toThrow(InvalidMoneyValue::class);
});
