<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Discounts\AppliedDiscount;
use RoundlyConsulting\Money\Discounts\Discount;
use RoundlyConsulting\Money\Discounts\DiscountBreakdown;
use RoundlyConsulting\Money\Discounts\DiscountStack;
use RoundlyConsulting\Money\Enums\DiscountTarget;
use RoundlyConsulting\Money\Enums\StackingStrategy;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Money;

/** @return list<string> "label:target:minor" */
function rows(DiscountBreakdown $breakdown): array
{
    return array_map(
        static fn (AppliedDiscount $row): string => $row->discount->label().':'.$row->target->value.':'.$row->amount->minor(),
        $breakdown->applied,
    );
}

function assertConsistent(DiscountBreakdown $breakdown): void
{
    $sum = Money::sum(array_map(static fn (AppliedDiscount $row): Money => $row->amount, $breakdown->applied), $breakdown->subtotal->currency());

    expect($sum->equals($breakdown->totalDiscount()))->toBeTrue()
        ->and($breakdown->subtotalDiscount->isLessThanOrEqualTo($breakdown->subtotal))->toBeTrue()
        ->and($breakdown->shippingDiscount->isLessThanOrEqualTo($breakdown->shipping))->toBeTrue();
}

beforeEach(function (): void {
    $this->tenPercent = Discount::percentage(10)->labelled('P10');
    $this->tenEuros = Discount::fixed(Money::ofMinor(1000, 'EUR'))->labelled('F10');
    $this->freeShipping = Discount::freeShipping()->labelled('FS');
    $this->subtotal = Money::ofMinor(10000, 'EUR');
    $this->shipping = Money::ofMinor(500, 'EUR');
});

/*
 * Hand-computed table — subtotal 100.00, shipping 5.00:
 *   P10 = 10 %, F10 = 10.00 fixed, FS = 100 % of shipping.
 */
it('applies sequentially in insertion order by default', function (): void {
    $breakdown = DiscountStack::of($this->tenPercent, $this->tenEuros, $this->freeShipping)->apply($this->subtotal, $this->shipping);

    expect(rows($breakdown))->toBe(['P10:subtotal:1000', 'F10:subtotal:1000', 'FS:shipping:500'])
        ->and($breakdown->subtotalDiscount->minor())->toBe('2000')
        ->and($breakdown->shippingDiscount->minor())->toBe('500')
        ->and($breakdown->discountedSubtotal()->minor())->toBe('8000')
        ->and($breakdown->discountedShipping()->minor())->toBe('0')
        ->and($breakdown->total()->minor())->toBe('8000');

    assertConsistent($breakdown);
});

it('orders by priority before insertion (percentages compound)', function (): void {
    $breakdown = DiscountStack::of($this->tenPercent, $this->tenEuros->withPriority(10))->apply($this->subtotal);

    expect(rows($breakdown))->toBe(['F10:subtotal:1000', 'P10:subtotal:900'])
        ->and($breakdown->subtotalDiscount->minor())->toBe('1900');
});

it('applies every discount to the original base when additive', function (): void {
    $breakdown = DiscountStack::of($this->tenEuros->withPriority(10), $this->tenPercent)
        ->using(StackingStrategy::Additive)
        ->apply($this->subtotal);

    expect(rows($breakdown))->toBe(['F10:subtotal:1000', 'P10:subtotal:1000']);
});

it('clamps an additive overshoot in reverse application order', function (): void {
    $a = Discount::percentage(60)->labelled('A');
    $b = Discount::percentage(60)->labelled('B');

    expect(rows(DiscountStack::of($a, $b)->using(StackingStrategy::Additive)->apply($this->subtotal)))->toBe(['A:subtotal:6000', 'B:subtotal:4000'])
        ->and(rows(DiscountStack::of($a, $b)->apply($this->subtotal)))->toBe(['A:subtotal:6000', 'B:subtotal:2400']);
});

it('picks the single best discount across targets, ties by application order', function (): void {
    expect(rows(DiscountStack::of($this->tenPercent, $this->tenEuros, $this->freeShipping)->using(StackingStrategy::BestOf)->apply($this->subtotal, $this->shipping)))->toBe(['P10:subtotal:1000'])
        ->and(rows(DiscountStack::of($this->tenPercent, $this->tenEuros->withPriority(1))->using(StackingStrategy::BestOf)->apply($this->subtotal)))->toBe(['F10:subtotal:1000'])
        ->and(rows(DiscountStack::of($this->freeShipping, Discount::fixed(Money::ofMinor(100, 'EUR'))->labelled('F1'))->using(StackingStrategy::BestOf)->apply($this->subtotal, $this->shipping)))->toBe(['FS:shipping:500']);
});

it('lets an exclusive discount compete with everything else, across targets', function (): void {
    $quarter = Discount::percentage(25)->exclusive()->labelled('E25');
    $exclusiveTen = Discount::percentage(10)->exclusive()->labelled('E10');

    $wins = DiscountStack::of($this->tenPercent, $quarter, $this->freeShipping)->apply($this->subtotal, $this->shipping);

    expect(rows($wins))->toBe(['E25:subtotal:2500'])
        ->and($wins->shippingDiscount->minor())->toBe('0')
        ->and(rows(DiscountStack::of($exclusiveTen, $this->freeShipping)->apply($this->subtotal, $this->shipping)))->toBe(['E10:subtotal:1000'])
        ->and(rows(DiscountStack::of($exclusiveTen, $this->tenEuros, $this->freeShipping)->apply($this->subtotal, $this->shipping)))->toBe(['F10:subtotal:1000', 'FS:shipping:500']);
});

it('prefers the combined option on a tie and breaks exclusive ties by priority', function (): void {
    $exclusive = Discount::fixed(Money::ofMinor(1500, 'EUR'))->exclusive()->labelled('E15');

    expect(rows(DiscountStack::of($exclusive, $this->tenEuros, $this->freeShipping)->apply($this->subtotal, $this->shipping)))->toBe(['F10:subtotal:1000', 'FS:shipping:500']);

    $low = Discount::percentage(10)->exclusive()->labelled('LOW');
    $high = Discount::fixed(Money::ofMinor(1000, 'EUR'))->exclusive()->withPriority(5)->labelled('HIGH');

    expect(rows(DiscountStack::of($low, $high)->apply($this->subtotal)))->toBe(['HIGH:subtotal:1000']);
});

it('caps the stack total from the last applied discount backwards', function (): void {
    $breakdown = DiscountStack::of($this->tenPercent, $this->tenEuros, $this->freeShipping)
        ->capTotalAt(Money::ofMinor(1200, 'EUR'))
        ->apply($this->subtotal, $this->shipping);

    expect(rows($breakdown))->toBe(['P10:subtotal:1000', 'F10:subtotal:200'])
        ->and($breakdown->totalDiscount()->minor())->toBe('1200')
        ->and($breakdown->shippingDiscount->minor())->toBe('0');

    assertConsistent($breakdown);
});

it('drops shipping discounts without shipping', function (): void {
    $breakdown = DiscountStack::of($this->freeShipping, $this->tenPercent)->apply($this->subtotal);

    expect(rows($breakdown))->toBe(['P10:subtotal:1000'])
        ->and($breakdown->shipping->isZero())->toBeTrue()
        ->and($breakdown->shippingDiscount->isZero())->toBeTrue();
});

it('handles zero shipping and an empty stack', function (): void {
    expect(rows(DiscountStack::of($this->freeShipping)->apply($this->subtotal, Money::zero('EUR'))))->toBe([])
        ->and(DiscountStack::of()->apply($this->subtotal)->total()->minor())->toBe('10000');
});

it('refuses shipping or a cap in another currency', function (): void {
    expect(fn () => DiscountStack::of()->apply($this->subtotal, Money::ofMinor(1, 'USD')))->toThrow(CurrencyMismatch::class)
        ->and(fn () => DiscountStack::of()->capTotalAt(Money::ofMinor(1, 'USD'))->apply($this->subtotal))->toThrow(CurrencyMismatch::class);
});

it('keeps every strategy × exclusivity × priority × cap combination consistent', function (): void {
    foreach (StackingStrategy::cases() as $strategy) {
        foreach ([false, true] as $withExclusive) {
            foreach ([0, 5] as $priority) {
                foreach ([null, Money::ofMinor(1500, 'EUR')] as $cap) {
                    $discounts = [$this->tenPercent, $this->tenEuros->withPriority($priority), $this->freeShipping];

                    if ($withExclusive) {
                        $discounts[] = Discount::percentage(15)->exclusive()->labelled('E15');
                    }

                    $stack = DiscountStack::of(...$discounts)->using($strategy);
                    $breakdown = ($cap === null ? $stack : $stack->capTotalAt($cap))->apply($this->subtotal, $this->shipping);

                    assertConsistent($breakdown);

                    if ($cap !== null) {
                        expect($breakdown->totalDiscount()->isLessThanOrEqualTo($cap))->toBeTrue();
                    }
                }
            }
        }
    }
});

it('reports which target each row applied to', function (): void {
    $breakdown = DiscountStack::of($this->freeShipping)->apply($this->subtotal, $this->shipping);

    expect($breakdown->applied[0]->target)->toBe(DiscountTarget::Shipping);
});
