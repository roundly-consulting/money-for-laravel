<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Exceptions\InvalidPercentage;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Percentage;
use RoundlyConsulting\Money\Tax\TaxRate;

it('builds rates from percentages and basis points', function (): void {
    expect(TaxRate::of(20, 'VAT')->percentage()->value())->toBe('20')
        ->and(TaxRate::of(20, 'VAT')->label())->toBe('VAT')
        ->and(TaxRate::of(Percentage::of('8.875'))->percentage()->value())->toBe('8.875')
        ->and(TaxRate::fromBasisPoints(1900)->percentage()->value())->toBe('19')
        ->and(TaxRate::fromBasisPoints(1900, 'reduced')->label())->toBe('reduced')
        ->and(TaxRate::zero()->isZero())->toBeTrue()
        ->and(TaxRate::of(20)->isZero())->toBeFalse()
        ->and(fn () => TaxRate::of(-1))->toThrow(InvalidPercentage::class);
});

it('computes net to gross and back', function (int $basisPoints, int $net, string $tax, string $gross): void {
    $rate = TaxRate::fromBasisPoints($basisPoints);
    $money = Money::ofMinor($net, 'EUR');

    expect($rate->taxOnNet($money)->minor())->toBe($tax)
        ->and($rate->grossFromNet($money)->minor())->toBe($gross);
})->with([
    '20 %' => [2000, 1000, '200', '1200'],
    '19 %' => [1900, 999, '190', '1189'],
    '8.875 % (bp rounded)' => [888, 1000, '89', '1089'],
    '0 %' => [0, 1000, '0', '1000'],
]);

it('extracts tax from gross exactly (shops G1 regression)', function (): void {
    expect(TaxRate::of(20)->netFromGross(Money::ofMinor(120, 'EUR'))->minor())->toBe('100')
        ->and(TaxRate::of(20)->taxInGross(Money::ofMinor(120, 'EUR'))->minor())->toBe('20')
        ->and(TaxRate::fromBasisPoints(1900)->netFromGross(Money::ofMinor(1000, 'EUR'))->minor())->toBe('840')
        ->and(TaxRate::fromBasisPoints(1900)->taxInGross(Money::ofMinor(1000, 'EUR'))->minor())->toBe('160')
        ->and(TaxRate::of('8.875')->netFromGross(Money::ofMinor(1089, 'EUR'))->minor())->toBe('1000')
        ->and(TaxRate::zero()->netFromGross(Money::ofMinor(1089, 'EUR'))->minor())->toBe('1089')
        ->and(TaxRate::of(20)->netFromGross(Money::ofMinor(125, 'EUR'), RoundingMode::HalfEven)->minor())->toBe('104');
});

it('matches shops PriceTest: tax scales with a discounted line', function (): void {
    $line = Money::ofMinor(1000, 'EUR')->percentage(90);

    expect(TaxRate::of(20)->taxOnNet($line)->minor())->toBe('180')
        ->and(TaxRate::of(20)->taxOnNet(Money::ofMinor(1000, 'EUR'))->add(TaxRate::of(10)->taxOnNet(Money::ofMinor(1000, 'EUR')))->minor())->toBe('300');
});

it('breaks amounts down in both directions', function (): void {
    $fromNet = TaxRate::of(20)->breakdownFromNet(Money::ofMinor(1000, 'EUR'));
    $fromGross = TaxRate::of(20)->breakdownFromGross(Money::ofMinor(1000, 'EUR'));

    expect([$fromNet->net->minor(), $fromNet->tax->minor(), $fromNet->gross->minor()])->toBe(['1000', '200', '1200'])
        ->and($fromNet->rate->percentage()->value())->toBe('20')
        ->and([$fromGross->net->minor(), $fromGross->tax->minor(), $fromGross->gross->minor()])->toBe(['833', '167', '1000']);
});
