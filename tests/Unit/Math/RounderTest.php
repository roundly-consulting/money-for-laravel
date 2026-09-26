<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Exceptions\DivisionByZero;
use RoundlyConsulting\Money\Math\IntegerString;
use RoundlyConsulting\Money\Math\Rounder;

/**
 * The Java BigDecimal rounding table: inputs × the eight native modes = 80 cases.
 *
 * @return array<string, array{0: RoundingMode, 1: list<string>}>
 */
function roundingTable(): array
{
    return [
        'AwayFromZero' => [RoundingMode::AwayFromZero, ['6', '3', '2', '2', '1', '-1', '-2', '-2', '-3', '-6']],
        'TowardsZero' => [RoundingMode::TowardsZero, ['5', '2', '1', '1', '1', '-1', '-1', '-1', '-2', '-5']],
        'PositiveInfinity' => [RoundingMode::PositiveInfinity, ['6', '3', '2', '2', '1', '-1', '-1', '-1', '-2', '-5']],
        'NegativeInfinity' => [RoundingMode::NegativeInfinity, ['5', '2', '1', '1', '1', '-1', '-2', '-2', '-3', '-6']],
        'HalfAwayFromZero' => [RoundingMode::HalfAwayFromZero, ['6', '3', '2', '1', '1', '-1', '-1', '-2', '-3', '-6']],
        'HalfTowardsZero' => [RoundingMode::HalfTowardsZero, ['5', '2', '2', '1', '1', '-1', '-1', '-2', '-2', '-5']],
        'HalfEven' => [RoundingMode::HalfEven, ['6', '2', '2', '1', '1', '-1', '-1', '-2', '-2', '-6']],
        'HalfOdd' => [RoundingMode::HalfOdd, ['5', '3', '2', '1', '1', '-1', '-1', '-2', '-3', '-5']],
    ];
}

const ROUNDING_INPUTS = ['55', '25', '16', '11', '10', '-10', '-11', '-16', '-25', '-55'];

it('matches the BigDecimal rounding table', function (RoundingMode $mode, array $expected): void {
    $actual = array_map(static fn (string $tenths): string => Rounder::divide($tenths, '10', $mode), ROUNDING_INPUTS);

    expect($actual)->toBe($expected);
})->with(roundingTable());

it('has a table that tells every mode apart (negative control)', function (): void {
    $rows = array_map(static fn (array $row): string => implode(',', $row[1]), roundingTable());

    expect(array_unique($rows))->toHaveCount(8);

    // A Rounder that ignored the mode would satisfy at most one row: prove the others differ.
    foreach (roundingTable() as $name => [$mode, $expected]) {
        foreach (roundingTable() as $other => [, $otherExpected]) {
            if ($name !== $other) {
                expect($expected)->not->toBe($otherExpected);
            }
        }
    }
});

it('rounds with a negative denominator by the value sign', function (): void {
    expect(Rounder::divide('25', '-10', RoundingMode::HalfAwayFromZero))->toBe('-3')
        ->and(Rounder::divide('-25', '-10', RoundingMode::HalfAwayFromZero))->toBe('3')
        ->and(Rounder::divide('0', '-10', RoundingMode::HalfAwayFromZero))->toBe('0')
        ->and(Rounder::divide('7', '7', RoundingMode::HalfAwayFromZero))->toBe('1');
});

it('refuses division by zero', function (): void {
    Rounder::divide('1', '0', RoundingMode::HalfEven);
})->throws(DivisionByZero::class);

it('agrees with bcround on seeded random decimals', function (): void {
    mt_srand(1999);

    for ($i = 0; $i < 10_000; $i++) {
        $numerator = randomInteger(30);
        $scale = mt_rand(1, 6);
        $mode = RoundingMode::cases()[mt_rand(0, 7)];

        $decimal = IntegerString::toDecimal($numerator, $scale);
        $expected = IntegerString::normalize(bcround($decimal, 0, $mode), PHP_INT_MAX);

        expect(Rounder::divide($numerator, '1'.str_repeat('0', $scale), $mode))->toBe($expected);
    }
});
