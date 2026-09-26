<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Discounts\Discount;
use RoundlyConsulting\Money\Enums\DiscountTarget;
use RoundlyConsulting\Money\Enums\DiscountType;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\InvalidDiscount;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Percentage;

function eur(int|string $minor): Money
{
    return Money::ofMinor($minor, 'EUR');
}

it('builds fixed, percentage and free-shipping discounts', function (): void {
    $fixed = Discount::fixed(eur(250));
    $percent = Discount::percentage('12.5');
    $shipping = Discount::freeShipping();

    expect($fixed->type())->toBe(DiscountType::Fixed)
        ->and($fixed->target())->toBe(DiscountTarget::Subtotal)
        ->and($fixed->fixedAmount()?->minor())->toBe('250')
        ->and($fixed->percent())->toBeNull()
        ->and($percent->type())->toBe(DiscountType::Percentage)
        ->and($percent->percent()?->value())->toBe('12.5')
        ->and($shipping->target())->toBe(DiscountTarget::Shipping)
        ->and($shipping->percent()?->value())->toBe('100')
        ->and($shipping->onSubtotal()->target())->toBe(DiscountTarget::Subtotal)
        ->and(Discount::percentage(Percentage::fromBasisPoints(850))->percent()?->value())->toBe('8.5');
});

it('carries cap, priority, exclusivity and label immutably', function (): void {
    $base = Discount::percentage(10);
    $decorated = $base->cappedAt(eur(300))->withPriority(5)->exclusive()->labelled('WELCOME10');

    expect($base->cap())->toBeNull()
        ->and($base->priority())->toBe(0)
        ->and($base->isExclusive())->toBeFalse()
        ->and($base->label())->toBeNull()
        ->and($decorated->cap()?->minor())->toBe('300')
        ->and($decorated->priority())->toBe(5)
        ->and($decorated->isExclusive())->toBeTrue()
        ->and($decorated->exclusive(false)->isExclusive())->toBeFalse()
        ->and($decorated->label())->toBe('WELCOME10');
});

it('re-expresses the coupons DiscountType behaviour', function (): void {
    expect(Discount::fixed(eur(250))->applyTo(eur(1000))->minor())->toBe('750')
        ->and(Discount::percentage(25)->applyTo(eur(1000))->minor())->toBe('750')
        ->and(Discount::fixed(eur(500))->applyTo(eur(100))->minor())->toBe('0')
        ->and(Discount::percentage(50)->cappedAt(eur(300))->amountFor(eur(1000))->minor())->toBe('300')
        ->and(Discount::percentage(50)->cappedAt(eur(300))->applyTo(eur(1000))->minor())->toBe('700')
        ->and(Discount::percentage(10)->cappedAt(eur(300))->amountFor(eur(1000))->minor())->toBe('100')
        ->and(Discount::fixed(eur(800))->cappedAt(eur(500))->amountFor(eur(1000))->minor())->toBe('500')
        ->and(Discount::percentage(0)->applyTo(eur(1000))->minor())->toBe('1000');
});

it('never exceeds the base or goes below zero', function (): void {
    expect(Discount::fixed(eur(500))->amountFor(eur(0))->minor())->toBe('0')
        ->and(Discount::fixed(eur(500))->amountFor(eur(-100))->minor())->toBe('0')
        ->and(Discount::percentage(100)->amountFor(eur(999))->minor())->toBe('999')
        ->and(Discount::freeShipping()->amountFor(eur(450))->minor())->toBe('450');
});

it('rounds a percentage once with the given mode', function (): void {
    expect(Discount::percentage('12.5')->amountFor(eur(1))->minor())->toBe('0')
        ->and(Discount::percentage(50)->amountFor(eur(5))->minor())->toBe('3')
        ->and(Discount::percentage(50)->amountFor(eur(5), RoundingMode::HalfEven)->minor())->toBe('2');
});

it('refuses invalid discounts', function (Closure $build): void {
    expect($build)->toThrow(InvalidDiscount::class);
})->with([
    'negative fixed' => [fn () => Discount::fixed(eur(-1))],
    'over 100 %' => [fn () => Discount::percentage(101)],
    'negative cap' => [fn () => Discount::percentage(10)->cappedAt(eur(-1))],
]);

it('refuses a fixed amount or cap in another currency', function (): void {
    expect(fn () => Discount::fixed(Money::ofMinor(1, 'USD'))->amountFor(eur(100)))->toThrow(CurrencyMismatch::class)
        ->and(fn () => Discount::percentage(10)->cappedAt(Money::ofMinor(1, 'USD'))->amountFor(eur(100)))->toThrow(CurrencyMismatch::class)
        ->and(fn () => Discount::fixed(Money::ofMinor(1, 'USD'))->amountFor(eur(0)))->toThrow(CurrencyMismatch::class);
});
