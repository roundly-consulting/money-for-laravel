<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Exceptions\AmountOverflow;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Math\IntegerString;

it('normalises loose integer input to the canonical grammar', function (int|string $input, string $expected): void {
    expect(IntegerString::normalize($input))->toBe($expected)
        ->and(IntegerString::isCanonical($expected))->toBeTrue();
})->with([
    [0, '0'],
    [-5, '-5'],
    [PHP_INT_MAX, '9223372036854775807'],
    [PHP_INT_MIN, '-9223372036854775808'],
    ['007', '7'],
    ['-0', '0'],
    ['-000', '0'],
    ['000', '0'],
    ['-0012', '-12'],
    ['92233720368547758070', '92233720368547758070'],
]);

it('rejects anything outside the integer input grammar', function (string $input): void {
    IntegerString::normalize($input);
})->throws(InvalidAmount::class)->with(['+5', ' 5', '5 ', '5.0', '5e3', '', '-', '١٢', '1_000', '0x1A', "5\n"]);

it('accepts 65 significant digits and refuses 66', function (): void {
    expect(IntegerString::normalize(str_repeat('9', 65)))->toHaveLength(65)
        ->and(IntegerString::normalize('-'.str_repeat('0', 10).str_repeat('9', 65)))->toHaveLength(66);

    IntegerString::normalize('1'.str_repeat('0', 65));
})->throws(AmountOverflow::class);

it('recognises only canonical strings as canonical', function (string $value, bool $canonical): void {
    expect(IntegerString::isCanonical($value))->toBe($canonical);
})->with([
    ['0', true], ['-1', true], ['10', true], [str_repeat('9', 65), true],
    ['-0', false], ['01', false], ['+1', false], ['', false], [str_repeat('9', 66), false], ['1.0', false],
]);

it('reports digits, sign and zero', function (): void {
    expect(IntegerString::digits('0'))->toBe(1)
        ->and(IntegerString::digits('-1234'))->toBe(4)
        ->and(IntegerString::sign('-3'))->toBe(-1)
        ->and(IntegerString::sign('0'))->toBe(0)
        ->and(IntegerString::sign('3'))->toBe(1)
        ->and(IntegerString::isZero('0'))->toBeTrue()
        ->and(IntegerString::isNegative('-1'))->toBeTrue()
        ->and(IntegerString::negate('0'))->toBe('0')
        ->and(IntegerString::negate('5'))->toBe('-5')
        ->and(IntegerString::negate('-5'))->toBe('5')
        ->and(IntegerString::abs('-5'))->toBe('5');
});

it('compares exactly like bccomp on seeded pairs', function (): void {
    mt_srand(4217);

    for ($i = 0; $i < 20_000; $i++) {
        $a = randomInteger(40);
        $b = mt_rand(0, 4) === 0 ? $a : (mt_rand(0, 1) === 0 ? IntegerString::negate($a) : randomInteger(40));

        expect(IntegerString::compare($a, $b))->toBe(bccomp($a, $b));
    }
});

it('compares equal-length, zero and mixed-sign values', function (string $a, string $b, int $expected): void {
    expect(IntegerString::compare($a, $b))->toBe($expected);
})->with([
    ['9', '10', -1], ['10', '9', 1], ['-9', '-10', 1], ['-10', '-9', -1],
    ['0', '0', 0], ['0', '-1', 1], ['-1', '0', -1], ['123', '124', -1], ['124', '124', 0],
]);

it('bridges to int exactly at the 64-bit bounds', function (): void {
    expect(IntegerString::toInt('9223372036854775807'))->toBe(PHP_INT_MAX)
        ->and(IntegerString::toInt('-9223372036854775808'))->toBe(PHP_INT_MIN)
        ->and(IntegerString::fitsInt('9223372036854775807'))->toBeTrue()
        ->and(IntegerString::fitsInt('9223372036854775808'))->toBeFalse()
        ->and(IntegerString::fitsInt('-9223372036854775808'))->toBeTrue()
        ->and(IntegerString::fitsInt('-9223372036854775809'))->toBeFalse()
        ->and(IntegerString::fitsInt('9223372036854775806'))->toBeTrue()
        ->and(IntegerString::fitsInt('10000000000000000000'))->toBeFalse();
});

it('refuses to bridge past int64', function (string $value): void {
    IntegerString::toInt($value);
})->throws(AmountOverflow::class)->with(['9223372036854775808', '-9223372036854775809']);

it('renders at a scale by string slicing', function (string $value, int $scale, bool $trim, string $expected): void {
    expect(IntegerString::toDecimal($value, $scale, $trim))->toBe($expected);
})->with([
    ['1050', 2, false, '10.50'],
    ['1050', 2, true, '10.5'],
    ['1000', 2, true, '10'],
    ['0', 2, false, '0.00'],
    ['0', 2, true, '0'],
    ['-50', 2, false, '-0.50'],
    ['-50', 2, true, '-0.5'],
    ['1', 3, false, '0.001'],
    ['5', 0, true, '5'],
    ['1205', 2, true, '12.05'],
    ['1500000000000000000', 18, true, '1.5'],
    ['92233720368547758070', 2, false, '922337203685477580.70'],
]);

it('caps computed results', function (): void {
    expect(IntegerString::capped('123', 'add'))->toBe('123');

    IntegerString::capped('1'.str_repeat('0', 65), 'add');
})->throws(AmountOverflow::class, 'add');
