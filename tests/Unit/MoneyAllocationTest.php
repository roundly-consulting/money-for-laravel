<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Exceptions\InvalidAllocation;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Math\IntegerString;
use RoundlyConsulting\Money\Money;

/** @param list<Money> $parts */
function minors(array $parts): array
{
    return array_map(static fn (Money $money): string => $money->minor(), $parts);
}

it('allocates by ratios, leftovers to the largest remainders', function (): void {
    expect(minors(Money::ofMinor(100, 'EUR')->allocate(1, 1, 1)))->toBe(['34', '33', '33'])
        ->and(minors(Money::ofMinor(5, 'EUR')->allocate(3, 7)))->toBe(['2', '3'])
        ->and(minors(Money::ofMinor(100, 'EUR')->allocate(70, 20, 10)))->toBe(['70', '20', '10'])
        ->and(minors(Money::ofMinor(100, 'EUR')->allocate('0.5', '0.25', '0.25')))->toBe(['50', '25', '25'])
        ->and(minors(Money::ofMinor(101, 'EUR')->allocate(1, 0, 1)))->toBe(['51', '0', '50'])
        ->and(minors(Money::ofMinor(-100, 'EUR')->allocate(1, 1, 1)))->toBe(['-34', '-33', '-33'])
        ->and(minors(Money::ofMinor(2, 'EUR')->allocate(1, 1, 1)))->toBe(['1', '1', '0']);
});

it('splits into equal parts', function (): void {
    expect(minors(Money::ofMinor(1000, 'EUR')->split(3)))->toBe(['334', '333', '333'])
        ->and(Money::ofMinor(1, 'EUR')->split(1)[0]->minor())->toBe('1')
        ->and(Money::ofMinor(1, 'EUR')->split(Money::MAX_SPLIT))->toHaveCount(10_000);
});

it('refuses bad ratios and part counts', function (Closure $operation): void {
    expect($operation)->toThrow(InvalidAllocation::class);
})->with([
    'no ratios' => [fn () => Money::ofMinor(1, 'EUR')->allocate()],
    'negative' => [fn () => Money::ofMinor(1, 'EUR')->allocate(1, -1)],
    'zero total' => [fn () => Money::ofMinor(1, 'EUR')->allocate(0, 0)],
    'zero parts' => [fn () => Money::ofMinor(1, 'EUR')->split(0)],
    'too many parts' => [fn () => Money::ofMinor(1, 'EUR')->split(10_001)],
]);

it('refuses malformed ratios', function (): void {
    Money::ofMinor(1, 'EUR')->allocate('1e2');
})->throws(InvalidAmount::class);

it('conserves every minor unit across seeded allocations', function (): void {
    mt_srand(5000);

    for ($i = 0; $i < 5_000; $i++) {
        $money = Money::ofMinor(randomInteger(38), 'EUR');
        $ratios = [];

        for ($r = mt_rand(1, 6); $r > 0; $r--) {
            $ratios[] = mt_rand(0, 3) === 0 ? 0 : mt_rand(1, 1000);
        }

        if (array_sum($ratios) === 0) {
            $ratios[0] = 1;
        }

        $parts = $money->allocate(...$ratios);

        expect(Money::sum($parts)->minor())->toBe($money->minor());

        $total = (string) array_sum($ratios);

        foreach ($parts as $index => $part) {
            // |part × Σr − m × r_i| < Σr  ⇔  |part − exact_i| < 1 minor unit
            $error = bcsub(bcmul($part->minor(), $total), bcmul($money->minor(), (string) $ratios[$index]));

            expect(IntegerString::compare(IntegerString::abs(IntegerString::normalize($error, PHP_INT_MAX)), $total))->toBe(-1);
        }

        expect(minors($money->allocate(...$ratios)))->toBe(minors($parts));
    }
});
