<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Exceptions\DivisionByZero;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Math\Calculator;
use RoundlyConsulting\Money\Math\IntegerString;

it('adds, subtracts and multiplies canonical integers', function (): void {
    expect(Calculator::add('1', '2'))->toBe('3')
        ->and(Calculator::add('-5', '5'))->toBe('0')
        ->and(Calculator::add('9223372036854775807', '1'))->toBe('9223372036854775808')
        ->and(Calculator::sub('-9223372036854775808', '1'))->toBe('-9223372036854775809')
        ->and(Calculator::sub('5', '5'))->toBe('0')
        ->and(Calculator::mul('-3', '4'))->toBe('-12')
        ->and(Calculator::mul('9223372036854775807', '2'))->toBe('18446744073709551614')
        ->and(Calculator::mul('0', '-99999999999999999999'))->toBe('0');
});

it('divides with truncation and a dividend-signed remainder', function (): void {
    expect(Calculator::divmod('-7', '2'))->toBe(['-3', '-1'])
        ->and(Calculator::divmod('7', '-2'))->toBe(['-3', '1'])
        ->and(Calculator::divmod('-1', '2'))->toBe(['0', '-1']);
});

it('refuses division by zero', function (): void {
    Calculator::divmod('1', '0');
})->throws(DivisionByZero::class);

it('guards bcmath against non-numeric operands', function (): void {
    Calculator::add('1'.str_repeat('0', 20), 'abc');
})->throws(InvalidAmount::class);

it('builds powers of ten and gcds', function (): void {
    expect(Calculator::pow10(0))->toBe('1')
        ->and(Calculator::pow10(3))->toBe('1000')
        ->and(Calculator::gcd('12', '-18'))->toBe('6')
        ->and(Calculator::gcd('0', '5'))->toBe('5')
        ->and(Calculator::gcd('0', '0'))->toBe('0')
        ->and(Calculator::gcd('17', '5'))->toBe('1');
});

it('takes the native fast path and bcmath to the same answer', function (): void {
    mt_srand(18);

    $edges = [
        '999999999999999999', '-999999999999999999', '1000000000000000000', '-1000000000000000000',
        '9223372036854775807', '-9223372036854775808', '9223372036854775806', '-9223372036854775807',
        '99999999999999999', '1', '-1', '0',
    ];

    $pairs = [];

    foreach ($edges as $a) {
        foreach ($edges as $b) {
            $pairs[] = [$a, $b];
        }
    }

    for ($i = 0; $i < 20_000; $i++) {
        $pairs[] = [randomInteger(20), randomInteger(20)];
    }

    foreach ($pairs as [$a, $b]) {
        expect(Calculator::add($a, $b))->toBe(IntegerString::normalize(bcadd($a, $b, 0), PHP_INT_MAX))
            ->and(Calculator::sub($a, $b))->toBe(IntegerString::normalize(bcsub($a, $b, 0), PHP_INT_MAX))
            ->and(Calculator::mul($a, $b))->toBe(IntegerString::normalize(bcmul($a, $b, 0), PHP_INT_MAX));
    }
});
