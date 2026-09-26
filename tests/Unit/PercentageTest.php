<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Exceptions\InvalidPercentage;
use RoundlyConsulting\Money\Exceptions\RoundingNecessary;
use RoundlyConsulting\Money\Percentage;
use RoundlyConsulting\Money\Ratio;

it('holds an exact decimal percent', function (): void {
    expect(Percentage::of('8.5')->value())->toBe('8.5')
        ->and(Percentage::of(20)->value())->toBe('20')
        ->and(Percentage::of('8.500000')->value())->toBe('8.5')
        ->and(Percentage::of('0')->isZero())->toBeTrue()
        ->and(Percentage::of('12.345678')->value())->toBe('12.345678');
});

it('converts from and to basis points', function (): void {
    expect(Percentage::fromBasisPoints(850)->value())->toBe('8.5')
        ->and(Percentage::fromBasisPoints(1900)->value())->toBe('19')
        ->and(Percentage::fromBasisPoints(0)->value())->toBe('0')
        ->and(Percentage::fromBasisPoints(1)->value())->toBe('0.01')
        ->and(Percentage::of('8.5')->basisPoints())->toBe(850)
        ->and(Percentage::of('8.875')->toRatio()->equals(Ratio::of(71, 800)))->toBeTrue();
});

it('refuses basis points finer than 0.01 %', function (): void {
    Percentage::of('8.875')->basisPoints();
})->throws(RoundingNecessary::class);

it('converts from a fraction', function (): void {
    expect(Percentage::fromRatio(Ratio::of(17, 200))->value())->toBe('8.5')
        ->and((string) Percentage::of('8.5')->toRatio())->toBe('17/200');
});

it('refuses a fraction that does not terminate within six digits', function (): void {
    Percentage::fromRatio(Ratio::of(1, 3));
})->throws(RoundingNecessary::class);

it('rejects negative, over-precise and malformed percentages', function (): void {
    expect(fn () => Percentage::of('-1'))->toThrow(InvalidPercentage::class)
        ->and(fn () => Percentage::fromBasisPoints(-1))->toThrow(InvalidPercentage::class)
        ->and(fn () => Percentage::of('1.1234567'))->toThrow(InvalidPercentage::class)
        ->and(fn () => Percentage::of('1e2'))->toThrow(InvalidAmount::class);
});

it('compares percentages exactly', function (): void {
    expect(Percentage::of('8.5')->compareTo(Percentage::of('8.49')))->toBe(1)
        ->and(Percentage::of('8.5')->compareTo(Percentage::of('8.50')))->toBe(0)
        ->and(Percentage::of(8)->compareTo(Percentage::of('8.000001')))->toBe(-1)
        ->and(Percentage::of('8.5')->equals(Percentage::fromBasisPoints(850)))->toBeTrue();
});
