<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\DivisionByZero;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Exceptions\InvalidPercentage;
use RoundlyConsulting\Money\Exceptions\RoundingNecessary;
use RoundlyConsulting\Money\Exceptions\UnknownCurrency;
use RoundlyConsulting\Money\Facades\Currencies;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Percentage;
use RoundlyConsulting\Money\Ratio;

const CANONICAL = '/\A(0|-?[1-9][0-9]{0,64})\z/';

it('builds from minor units, int or integer string', function (): void {
    expect(Money::ofMinor(250, 'EUR')->minor())->toBe('250')
        ->and(Money::ofMinor('007', 'EUR')->minor())->toBe('7')
        ->and(Money::ofMinor('-0', 'EUR')->minor())->toBe('0')
        ->and(Money::ofMinor(PHP_INT_MIN, 'EUR')->minor())->toBe('-9223372036854775808')
        ->and(Money::ofMinor('9223372036854775808', 'EUR')->minor())->toBe('9223372036854775808')
        ->and(Money::ofMinor(1, 'gbp')->currency()->code)->toBe('GBP')
        ->and(Money::ofMinor(1, Currency::custom('PTS', 0))->currency()->code)->toBe('PTS');
});

it('never accepts a float', function (): void {
    Money::ofMinor(1.0, 'EUR');
})->throws(TypeError::class);

it('builds from major units without the float bug', function (): void {
    expect(Money::ofMajor('1.10', 'EUR')->minor())->toBe('110')
        ->and(Money::ofMajor('19.99', 'EUR')->minor())->toBe('1999')
        ->and(Money::ofMajor('10.500', 'EUR')->minor())->toBe('1050')
        ->and(Money::ofMajor('+3', 'EUR')->minor())->toBe('300')
        ->and(Money::ofMajor(12, 'EUR')->minor())->toBe('1200')
        ->and(Money::ofMajor('-0.01', 'EUR')->minor())->toBe('-1')
        ->and(Money::ofMajor('10.005', 'EUR', RoundingMode::HalfEven)->minor())->toBe('1000')
        ->and(Money::ofMajor('10.005', 'EUR', RoundingMode::HalfAwayFromZero)->minor())->toBe('1001');
});

it('refuses silent truncation of excess precision', function (): void {
    Money::ofMajor('10.005', 'EUR');
})->throws(RoundingNecessary::class);

it('respects every currency exponent', function (string $amount, Currency|string $currency, string $minor): void {
    expect(Money::ofMajor($amount, $currency)->minor())->toBe($minor);
})->with([
    'JPY 0' => ['1234', 'JPY', '1234'],
    'BHD 3' => ['1.234', 'BHD', '1234'],
    'CLF 4' => ['1.2345', 'CLF', '12345'],
    'PTS 0' => ['7', Currency::custom('PTS', 0), '7'],
    'BTC 8' => ['0.00000001', Currency::custom('BTC', 8), '1'],
    'ETH 18' => ['1.5', Currency::custom('ETH', 18), '1500000000000000000'],
]);

it('handles registered wei-precision currencies beyond int64', function (): void {
    config(['money.schema.currency_length' => 10]);
    Currencies::register(Currency::custom('ETH', 18, 'Ether', 'Ξ'));

    $money = Money::ofMajor('1.5', 'ETH');

    expect($money->minor())->toBe('1500000000000000000')
        ->and(Money::ofMajor('10', 'ETH')->fitsInt())->toBeFalse()
        ->and(Money::ofMajor('10', 'ETH')->toDecimal(true))->toBe('10');
});

it('rescales provider amounts', function (): void {
    expect(Money::ofScaled(9990, 3, 'EUR')->minor())->toBe('999')
        ->and(Money::ofScaled('1990000', 6, 'USD')->minor())->toBe('199')
        ->and(Money::ofScaled(5, 0, 'EUR')->minor())->toBe('500')
        ->and(Money::ofScaled(9995, 3, 'EUR', RoundingMode::HalfEven)->minor())->toBe('1000')
        ->and(fn () => Money::ofScaled(9995, 3, 'EUR'))->toThrow(RoundingNecessary::class)
        ->and(fn () => Money::ofScaled(1, 37, 'EUR'))->toThrow(InvalidAmount::class)
        ->and(fn () => Money::ofScaled(1, -1, 'EUR'))->toThrow(InvalidAmount::class);
});

it('rejects malformed amounts and unknown currencies', function (Closure $build, string $exception): void {
    expect($build)->toThrow($exception);
})->with([
    [fn () => Money::ofMinor('+5', 'EUR'), InvalidAmount::class],
    [fn () => Money::ofMinor('5.0', 'EUR'), InvalidAmount::class],
    [fn () => Money::ofMinor('١٢', 'EUR'), InvalidAmount::class],
    [fn () => Money::ofMajor('1e3', 'EUR'), InvalidAmount::class],
    [fn () => Money::ofMajor('1,5', 'EUR'), InvalidAmount::class],
    [fn () => Money::ofMinor(1, 'XYZ'), UnknownCurrency::class],
]);

it('bridges to int only within int64', function (): void {
    expect(Money::ofMinor(PHP_INT_MAX, 'EUR')->minorInt())->toBe(PHP_INT_MAX)
        ->and(Money::ofMinor(PHP_INT_MIN, 'EUR')->minorInt())->toBe(PHP_INT_MIN)
        ->and(Money::ofMinor(PHP_INT_MAX, 'EUR')->fitsInt())->toBeTrue()
        ->and(Money::ofMinor('9223372036854775808', 'EUR')->fitsInt())->toBeFalse()
        ->and(Money::ofMinor('-9223372036854775809', 'EUR')->fitsInt())->toBeFalse()
        ->and(fn () => Money::ofMinor('9223372036854775808', 'EUR')->minorInt())->toThrow(AmountOverflow::class)
        ->and(fn () => Money::ofMinor('-9223372036854775809', 'EUR')->minorInt())->toThrow(AmountOverflow::class);
});

it('computes exactly beyond int64', function (): void {
    $max = Money::ofMinor(PHP_INT_MAX, 'EUR');

    expect($max->add(Money::ofMinor(1, 'EUR'))->minor())->toBe('9223372036854775808')
        ->and($max->multiply(2)->minor())->toBe('18446744073709551614')
        ->and(Money::ofMinor(PHP_INT_MIN, 'EUR')->negate()->minor())->toBe('9223372036854775808')
        ->and(Money::ofMinor(PHP_INT_MIN, 'EUR')->abs()->minor())->toBe('9223372036854775808')
        ->and(Money::ofMinor('92233720368547758070', 'EUR')->add(Money::ofMinor(1, 'EUR'))->minor())->toBe('92233720368547758071');
});

it('caps results at 65 digits', function (): void {
    $big = Money::ofMinor(str_repeat('9', 65), 'EUR');

    expect($big->digits())->toBe(65)
        ->and(fn () => $big->add(Money::ofMinor(1, 'EUR')))->toThrow(AmountOverflow::class, 'add')
        ->and(fn () => $big->negate()->subtract(Money::ofMinor(1, 'EUR')))->toThrow(AmountOverflow::class, 'subtract')
        ->and(fn () => $big->multiply(10))->toThrow(AmountOverflow::class, 'multiply')
        ->and(fn () => Money::ofMinor(str_repeat('9', 66), 'EUR'))->toThrow(AmountOverflow::class)
        ->and(fn () => Money::ofMajor(str_repeat('9', 64), 'EUR'))->toThrow(AmountOverflow::class);
});

it('reports digits', function (): void {
    expect(Money::zero('EUR')->digits())->toBe(1)
        ->and(Money::ofMinor(-1234, 'EUR')->digits())->toBe(4);
});

it('renders the exact toDecimal grammar', function (string $minor, Currency|string $currency, bool $trim, string $expected): void {
    expect(Money::ofMinor($minor, $currency)->toDecimal($trim))->toBe($expected);
})->with([
    ['1230', 'EUR', true, '12.3'],
    ['1000', 'EUR', true, '10'],
    ['0', 'EUR', true, '0'],
    ['0', 'EUR', false, '0.00'],
    ['-50', 'EUR', true, '-0.5'],
    ['-50', 'EUR', false, '-0.50'],
    ['1', 'BHD', true, '0.001'],
    ['5', 'JPY', true, '5'],
    ['5', 'JPY', false, '5'],
    ['1205', 'EUR', true, '12.05'],
    ['1500000000000000000', Currency::custom('ETH', 18), true, '1.5'],
    ['92233720368547758070', 'EUR', false, '922337203685477580.70'],
]);

it('rescales to another scale', function (): void {
    expect(Money::ofMinor(1999, 'EUR')->toScaled(6))->toBe('19990000')
        ->and(Money::ofMinor(1995, 'EUR')->toScaled(1))->toBe('200')
        ->and(Money::ofMinor(1995, 'EUR')->toScaled(1, RoundingMode::HalfEven))->toBe('200')
        ->and(Money::ofMinor(1985, 'EUR')->toScaled(1, RoundingMode::HalfEven))->toBe('198');
});

it('adds and subtracts several amounts at once', function (): void {
    $a = Money::ofMinor(100, 'EUR');

    expect($a->add(Money::ofMinor(50, 'EUR'), Money::ofMinor(25, 'EUR'))->minor())->toBe('175')
        ->and($a->subtract(Money::ofMinor(100, 'EUR'))->minor())->toBe('0')
        ->and($a->subtract(Money::ofMinor(150, 'EUR'))->minor())->toBe('-50')
        ->and($a->add()->equals($a))->toBeTrue();
});

it('multiplies and divides with one rounding', function (): void {
    $odd = Money::ofMinor(1001, 'EUR');

    expect($odd->multiply('0.5')->minor())->toBe('501')
        ->and($odd->multiply('0.5', RoundingMode::HalfEven)->minor())->toBe('500')
        ->and($odd->multiply('0.5', RoundingMode::TowardsZero)->minor())->toBe('500')
        ->and($odd->multiply('1.19')->minor())->toBe('1191')
        ->and($odd->multiply(Ratio::of(1, 3))->minor())->toBe('334')
        ->and($odd->multiply(-2)->minor())->toBe('-2002')
        ->and($odd->divide(3)->minor())->toBe('334')
        ->and($odd->divide(3, RoundingMode::TowardsZero)->minor())->toBe('333')
        ->and($odd->divide('0.5')->minor())->toBe('2002')
        ->and($odd->divide(Ratio::of(2))->minor())->toBe('501')
        ->and(Money::ofMinor(-1001, 'EUR')->divide(2)->minor())->toBe('-501');
});

it('refuses division by zero and malformed factors', function (): void {
    $money = Money::ofMinor(100, 'EUR');

    expect(fn () => $money->divide(0))->toThrow(DivisionByZero::class)
        ->and(fn () => $money->divide('0.00'))->toThrow(DivisionByZero::class)
        ->and(fn () => $money->multiply('1e3'))->toThrow(InvalidAmount::class)
        ->and(fn () => $money->multiply('1,5'))->toThrow(InvalidAmount::class)
        ->and(fn () => $money->multiply(' 2'))->toThrow(InvalidAmount::class);
});

it('takes percentages', function (): void {
    $money = Money::ofMinor(6747, 'EUR');

    expect($money->percentage('20')->minor())->toBe('1349')
        ->and($money->percentage(Percentage::of('8.5'))->minor())->toBe('573')
        ->and($money->percentage('8.5', RoundingMode::TowardsZero)->minor())->toBe('573')
        ->and($money->percentage(0)->minor())->toBe('0')
        ->and(fn () => $money->percentage('-1'))->toThrow(InvalidPercentage::class);
});

it('rounds to cash increments', function (): void {
    expect(Money::ofMinor(1033, 'CHF')->roundTo(5)->minor())->toBe('1035')
        ->and(Money::ofMinor(1032, 'CHF')->roundTo(5)->minor())->toBe('1030')
        ->and(Money::ofMinor(-1033, 'CHF')->roundTo('5')->minor())->toBe('-1035')
        ->and(Money::ofMinor(1032, 'CHF')->roundTo(5, RoundingMode::PositiveInfinity)->minor())->toBe('1035')
        ->and(fn () => Money::ofMinor(1, 'CHF')->roundTo(0))->toThrow(InvalidAmount::class)
        ->and(fn () => Money::ofMinor(1, 'CHF')->roundTo(-5))->toThrow(InvalidAmount::class);
});

it('computes the exact ratio between two amounts', function (): void {
    expect((string) Money::ofMinor(150, 'EUR')->ratioTo(Money::ofMinor(100, 'EUR')))->toBe('3/2')
        ->and(fn () => Money::ofMinor(1, 'EUR')->ratioTo(Money::zero('EUR')))->toThrow(DivisionByZero::class)
        ->and(fn () => Money::ofMinor(1, 'EUR')->ratioTo(Money::ofMinor(1, 'USD')))->toThrow(CurrencyMismatch::class);
});

it('compares within one currency', function (): void {
    $ten = Money::ofMajor('10', 'EUR');
    $five = Money::ofMajor('5', 'EUR');

    expect($ten->compareTo($five))->toBe(1)
        ->and($five->compareTo($ten))->toBe(-1)
        ->and($ten->compareTo(Money::ofMinor('01000', 'EUR')))->toBe(0)
        ->and($ten->isGreaterThan($five))->toBeTrue()
        ->and($ten->isGreaterThanOrEqualTo($ten))->toBeTrue()
        ->and($five->isLessThan($ten))->toBeTrue()
        ->and($five->isLessThanOrEqualTo($five))->toBeTrue()
        ->and($ten->equals(Money::ofMinor(1000, 'EUR')))->toBeTrue()
        ->and($ten->isSameCurrency(Money::zero('EUR')))->toBeTrue()
        ->and(Money::zero('EUR')->isZero())->toBeTrue()
        ->and($ten->isPositive())->toBeTrue()
        ->and($ten->negate()->isNegative())->toBeTrue()
        ->and(Money::zero('EUR')->isPositive())->toBeFalse()
        ->and(Money::zero('EUR')->isNegative())->toBeFalse();
});

it('never throws from equals across currencies or exponents', function (): void {
    expect(Money::ofMinor(100, 'EUR')->equals(Money::ofMinor(100, 'USD')))->toBeFalse()
        ->and(Money::ofMinor(100, 'EUR')->equals(Money::ofMinor(100, new Currency('EUR', 3, 'Euro'))))->toBeFalse()
        ->and(Money::ofMinor(100, 'EUR')->isSameCurrency(Money::ofMinor(1, 'USD')))->toBeFalse();
});

it('throws CurrencyMismatch from every binary and ordering operation', function (Closure $operation): void {
    expect($operation)->toThrow(CurrencyMismatch::class);
})->with([
    'add' => [fn () => Money::ofMinor(1, 'EUR')->add(Money::ofMinor(1, 'USD'))],
    'subtract' => [fn () => Money::ofMinor(1, 'EUR')->subtract(Money::ofMinor(1, 'USD'))],
    'compareTo' => [fn () => Money::ofMinor(1, 'EUR')->compareTo(Money::ofMinor(1, 'USD'))],
    'isGreaterThan' => [fn () => Money::ofMinor(1, 'EUR')->isGreaterThan(Money::ofMinor(1, 'USD'))],
    'isGreaterThanOrEqualTo' => [fn () => Money::ofMinor(1, 'EUR')->isGreaterThanOrEqualTo(Money::ofMinor(1, 'USD'))],
    'isLessThan' => [fn () => Money::ofMinor(1, 'EUR')->isLessThan(Money::ofMinor(1, 'USD'))],
    'isLessThanOrEqualTo' => [fn () => Money::ofMinor(1, 'EUR')->isLessThanOrEqualTo(Money::ofMinor(1, 'USD'))],
    'exponent differs' => [fn () => Money::ofMinor(1, 'EUR')->add(Money::ofMinor(1, new Currency('EUR', 3, 'Euro')))],
    'sum' => [fn () => Money::sum([Money::ofMinor(1, 'EUR'), Money::ofMinor(1, 'USD')])],
]);

it('only ever produces canonical minor strings', function (): void {
    $eur = Money::ofMinor(1001, 'EUR');
    $zero = Money::zero('EUR');

    $outputs = [
        Money::ofMinor('-000', 'EUR'), Money::ofMajor('-0.00', 'EUR'), Money::ofScaled('-0', 3, 'EUR'),
        $zero->negate(), $zero->abs(), $eur->subtract($eur), $eur->negate()->add($eur),
        $eur->multiply(0), $eur->multiply('-0.0001'), $eur->divide(-3), $eur->percentage('0.000001'),
        $eur->roundTo(1000), $eur->negate()->roundTo(1000), $eur->negate()->multiply('0.0001'),
        ...$eur->allocate(1, 0, 2), ...$eur->negate()->split(7), ...Money::zero('EUR')->split(3),
        Money::sum([], 'EUR'), Money::average([$eur, $eur->negate()]),
    ];

    foreach ($outputs as $money) {
        expect($money->minor())->toMatch(CANONICAL);
    }
});

it('renders deterministically as a string', function (): void {
    expect((string) Money::ofMinor(-50, 'EUR'))->toBe('-0.50 EUR')
        ->and((string) Money::ofMinor(1050, 'EUR'))->toBe('10.50 EUR')
        ->and((string) Money::zero('JPY'))->toBe('0 JPY');
});
