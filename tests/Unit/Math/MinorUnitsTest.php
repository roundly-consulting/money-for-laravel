<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Exceptions\AmountOverflow;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Exceptions\RoundingNecessary;
use RoundlyConsulting\Money\Math\MinorUnits;

it('normalises minor amounts', function (): void {
    expect(MinorUnits::normalize('007'))->toBe('7')
        ->and(MinorUnits::normalize('-0'))->toBe('0')
        ->and(MinorUnits::normalize(-12))->toBe('-12');
});

it('renders the credits formatting expectations', function (): void {
    // credits' FormatCreditsAction: 123450 at scale 2 → "1234.50"; to scale 0 half-up → 1235, half-down → 1234.
    expect(MinorUnits::toDecimal(123450, 2))->toBe('1234.50')
        ->and(MinorUnits::toDecimal(123450, 2, true))->toBe('1234.5')
        ->and(MinorUnits::rescale(123450, 2, 0, RoundingMode::HalfAwayFromZero))->toBe('1235')
        ->and(MinorUnits::rescale(123450, 2, 0, RoundingMode::HalfTowardsZero))->toBe('1234')
        ->and(MinorUnits::rescale(123449, 2, 0))->toBe('1234')
        ->and(MinorUnits::rescale(-123450, 2, 0))->toBe('-1235');
});

it('parses decimals into minor units', function (): void {
    expect(MinorUnits::fromDecimal('10.5', 2))->toBe('1050')
        ->and(MinorUnits::fromDecimal('10.555', 2, RoundingMode::HalfEven))->toBe('1056')
        ->and(MinorUnits::fromDecimal('-0.001', 3))->toBe('-1');
});

it('refuses excess precision without a mode', function (): void {
    MinorUnits::fromDecimal('10.555', 2);
})->throws(RoundingNecessary::class);

it('scales up beyond int64 exactly', function (): void {
    expect(MinorUnits::rescale(PHP_INT_MAX, 0, 6))->toBe('9223372036854775807000000')
        ->and(MinorUnits::rescale(0, 0, 6))->toBe('0')
        ->and(MinorUnits::rescale(5, 2, 2))->toBe('5');
});

it('caps a scale-up at 65 digits', function (): void {
    MinorUnits::rescale(str_repeat('9', 60), 0, 6);
})->throws(AmountOverflow::class);

it('refuses scales outside 0..36', function (int $scale): void {
    MinorUnits::toDecimal(1, $scale);
})->throws(InvalidAmount::class)->with([-1, 37]);

it('bridges to int only inside int64', function (): void {
    expect(MinorUnits::toInt('9223372036854775807'))->toBe(PHP_INT_MAX)
        ->and(MinorUnits::toInt(-5))->toBe(-5);

    MinorUnits::toInt('9223372036854775808');
})->throws(AmountOverflow::class);
