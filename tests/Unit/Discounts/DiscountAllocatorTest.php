<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Discounts\DiscountAllocator;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\InvalidAllocation;
use RoundlyConsulting\Money\Exceptions\InvalidDiscount;
use RoundlyConsulting\Money\Money;

function spread(int|string $discount, int|string ...$lines): array
{
    return array_map(
        static fn (Money $share): string => $share->minor(),
        (new DiscountAllocator)->allocate(Money::ofMinor($discount, 'EUR'), ...array_map(static fn (int|string $line): Money => Money::ofMinor($line, 'EUR'), $lines)),
    );
}

it('spreads a discount proportionally without losing a cent', function (): void {
    expect(spread(100, 1000, 2000, 3000))->toBe(['17', '33', '50'])
        ->and(spread(1000, 1000, 0))->toBe(['1000', '0'])
        ->and(spread(0, 500, 500))->toBe(['0', '0'])
        ->and(spread(0, 0, 0))->toBe(['0', '0'])
        ->and(spread(3, 1, 1, 1))->toBe(['1', '1', '1']);
});

it('is bound in the container', function (): void {
    expect(app(DiscountAllocator::class))->toBe(app(DiscountAllocator::class));
});

it('never gives a line more than its total, and conserves the discount', function (): void {
    mt_srand(8);

    for ($i = 0; $i < 2_000; $i++) {
        $lines = [];

        for ($n = mt_rand(1, 6); $n > 0; $n--) {
            $lines[] = randomInteger(38, false);
        }

        $total = Money::sum(array_map(static fn (string $line): Money => Money::ofMinor($line, 'EUR'), $lines));
        $discount = $total->multiply(randomFraction(), RoundingMode::TowardsZero);

        $shares = (new DiscountAllocator)->allocate($discount, ...array_map(static fn (string $line): Money => Money::ofMinor($line, 'EUR'), $lines));

        expect(Money::sum($shares)->equals($discount))->toBeTrue();

        foreach ($shares as $index => $share) {
            expect($share->isNegative())->toBeFalse()
                ->and($share->isLessThanOrEqualTo(Money::ofMinor($lines[$index], 'EUR')))->toBeTrue();
        }
    }
});

function randomFraction(): string
{
    return '0.'.str_pad((string) mt_rand(0, 999_999), 6, '0', STR_PAD_LEFT);
}

it('refuses impossible allocations', function (Closure $allocate, string $exception): void {
    expect($allocate)->toThrow($exception);
})->with([
    'no lines' => [fn () => (new DiscountAllocator)->allocate(Money::ofMinor(1, 'EUR')), InvalidAllocation::class],
    'negative discount' => [fn () => spread(-1, 10), InvalidDiscount::class],
    'negative line' => [fn () => spread(1, 10, -5), InvalidDiscount::class],
    'exceeds lines' => [fn () => spread(11, 10), InvalidDiscount::class],
    'zero lines, positive discount' => [fn () => spread(1, 0, 0), InvalidDiscount::class],
    'currency' => [fn () => (new DiscountAllocator)->allocate(Money::ofMinor(1, 'EUR'), Money::ofMinor(5, 'USD')), CurrencyMismatch::class],
]);
