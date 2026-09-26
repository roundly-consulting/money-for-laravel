<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Exceptions\RoundingNecessary;
use RoundlyConsulting\Money\Math\DecimalString;

it('normalises decimals to the canonical form', function (int|string $input, string $expected): void {
    expect(DecimalString::normalize($input))->toBe($expected);
})->with([
    [12, '12'],
    ['+010.500', '10.5'],
    ['-0.00', '0'],
    ['-0', '0'],
    ['0.10', '0.1'],
    ['000.001', '0.001'],
    ['19.99', '19.99'],
    ['-1234.50', '-1234.5'],
    ['10.000', '10'],
]);

it('rejects non-decimal input', function (string $input): void {
    DecimalString::normalize($input);
})->throws(InvalidAmount::class)->with(['1e3', '1,5', ' 2', '2 ', '.5', '5.', '', '-', '1.2.3', '١٢', '1 000', '++1']);

it('caps decimals at 90 digits', function (): void {
    expect(DecimalString::normalize(str_repeat('1', 45).'.'.str_repeat('1', 45)))->toHaveLength(91);

    DecimalString::normalize(str_repeat('1', 46).'.'.str_repeat('1', 45));
})->throws(InvalidAmount::class);

it('refuses over-long raw input before parsing', function (): void {
    DecimalString::normalize(str_repeat('0', 200).'1');
})->throws(InvalidAmount::class);

it('validates the grammar without normalising', function (): void {
    expect(DecimalString::isValid('1.5'))->toBeTrue()
        ->and(DecimalString::isValid('+1'))->toBeTrue()
        ->and(DecimalString::isValid('1e5'))->toBeFalse();
});

it('reports scale and unscaled digits', function (): void {
    expect(DecimalString::scale('12.345'))->toBe(3)
        ->and(DecimalString::scale('12'))->toBe(0)
        ->and(DecimalString::unscaled('-12.34'))->toBe('-1234')
        ->and(DecimalString::unscaled('0.05'))->toBe('5');
});

it('scales decimals to integers exactly', function (string $decimal, int $scale, string $expected): void {
    expect(DecimalString::toScaledInteger($decimal, $scale))->toBe($expected);
})->with([
    ['10.5', 2, '1050'],
    ['10.500', 2, '1050'],
    ['0', 2, '0'],
    ['-1.1', 2, '-110'],
    ['1234', 0, '1234'],
    ['1.5', 18, '1500000000000000000'],
]);

it('refuses excess precision without a rounding mode', function (): void {
    DecimalString::toScaledInteger('10.005', 2);
})->throws(RoundingNecessary::class);

it('rounds excess precision once when asked', function (): void {
    expect(DecimalString::toScaledInteger('10.005', 2, RoundingMode::HalfEven))->toBe('1000')
        ->and(DecimalString::toScaledInteger('10.005', 2, RoundingMode::HalfAwayFromZero))->toBe('1001')
        ->and(DecimalString::toScaledInteger('-10.005', 2, RoundingMode::HalfAwayFromZero))->toBe('-1001');
});

it('renders floats as their shortest round-trip decimal', function (float $input, string $expected): void {
    expect(DecimalString::fromFloat($input))->toBe($expected);
})->with([
    [1.1, '1.1'],
    [2.2, '2.2'],
    [19.99, '19.99'],
    [1e-7, '0.0000001'],
    [-0.0, '0'],
    [0.0, '0'],
    [100.0, '100'],
    [0.30000000000000004, '0.30000000000000004'],
    [-12.5, '-12.5'],
    [123456789.125, '123456789.125'],
    [9007199254740991.0, '9007199254740991'],
]);

it('ignores the host serialize_precision ini', function (): void {
    $previous = ini_get('serialize_precision');
    ini_set('serialize_precision', '17');
    ini_set('precision', '17');

    try {
        expect(DecimalString::fromFloat(1.1))->toBe('1.1');
    } finally {
        ini_set('serialize_precision', (string) $previous);
        ini_set('precision', '14');
    }
});

it('rejects floats that cannot be recovered exactly', function (float $input): void {
    DecimalString::fromFloat($input);
})->throws(InvalidAmount::class)->with([NAN, INF, -INF, 9007199254740992.0, -1e20]);
