<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Exceptions\InvalidRatio;
use RoundlyConsulting\Money\Ratio;

it('reduces decimal and integer terms exactly', function (): void {
    $rate = Ratio::of('1.0854');

    expect($rate->numerator())->toBe('5427')
        ->and($rate->denominator())->toBe('5000')
        ->and((string) $rate)->toBe('5427/5000')
        ->and((string) Ratio::of(17, 200))->toBe('17/200')
        ->and((string) Ratio::of(10, 4))->toBe('5/2')
        ->and((string) Ratio::of('1.5', '0.25'))->toBe('6/1')
        ->and((string) Ratio::of(3, -6))->toBe('-1/2')
        ->and((string) Ratio::of(0, 7))->toBe('0/1');
});

it('builds identities', function (): void {
    expect((string) Ratio::one())->toBe('1/1')
        ->and(Ratio::zero()->isZero())->toBeTrue()
        ->and(Ratio::one()->isZero())->toBeFalse();
});

it('multiplies, divides and inverts exactly', function (): void {
    $eurCzk = Ratio::of('25.10');
    $eurUsd = Ratio::of('1.0854');

    $usdCzk = $eurCzk->divide($eurUsd);

    expect((string) $usdCzk)->toBe('125500/5427')
        ->and($usdCzk->multiply($eurUsd)->equals($eurCzk))->toBeTrue()
        ->and((string) Ratio::of(2, 3)->invert())->toBe('3/2')
        ->and((string) Ratio::of(-2, 3)->invert())->toBe('-3/2');
});

it('refuses a zero denominator and inverting zero', function (): void {
    expect(fn () => Ratio::of(1, 0))->toThrow(InvalidRatio::class)
        ->and(fn () => Ratio::zero()->invert())->toThrow(InvalidRatio::class);
});

it('refuses terms beyond 80 digits after reduction', function (): void {
    Ratio::of(1, '1'.str_repeat('0', 40))->multiply(Ratio::of(1, '3'.str_repeat('0', 40)));
})->throws(InvalidRatio::class);

it('rejects non-decimal terms', function (): void {
    Ratio::of('1e3');
})->throws(InvalidAmount::class);

it('compares and tests sign', function (): void {
    expect(Ratio::of(1, 3)->compareTo(Ratio::of(1, 2)))->toBe(-1)
        ->and(Ratio::of(2, 4)->compareTo(Ratio::of(1, 2)))->toBe(0)
        ->and(Ratio::of(-1, 2)->compareTo(Ratio::of(-1, 3)))->toBe(-1)
        ->and(Ratio::of(-1, 2)->isNegative())->toBeTrue()
        ->and(Ratio::of(1, 2)->equals(Ratio::of('0.5')))->toBeTrue();
});

it('renders decimals rounded once or exactly', function (): void {
    expect(Ratio::of(2, 3)->toDecimal(4))->toBe('0.6667')
        ->and(Ratio::of(2, 3)->toDecimal(4, RoundingMode::TowardsZero))->toBe('0.6666')
        ->and(Ratio::of(-5, 2)->toDecimal(0, RoundingMode::HalfEven))->toBe('-2')
        ->and(Ratio::of('1.0854')->toExactDecimal())->toBe('1.0854')
        ->and(Ratio::of(10)->toExactDecimal())->toBe('10')
        ->and(Ratio::of(1, 3)->toExactDecimal())->toBeNull();
});
