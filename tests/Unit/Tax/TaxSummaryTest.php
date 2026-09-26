<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\EmptyMoneyCollection;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Tax\TaxBreakdown;
use RoundlyConsulting\Money\Tax\TaxRate;
use RoundlyConsulting\Money\Tax\TaxSummary;

it('groups per rate without re-rounding, sorted by rate', function (): void {
    $standard = TaxRate::of(20, 'standard');
    $reduced = TaxRate::of(10, 'reduced');

    $summary = TaxSummary::of(
        $standard->breakdownFromNet(Money::ofMinor(333, 'EUR')),
        $reduced->breakdownFromNet(Money::ofMinor(1000, 'EUR')),
        $standard->breakdownFromNet(Money::ofMinor(333, 'EUR')),
        TaxRate::zero()->breakdownFromNet(Money::ofMinor(1000, 'EUR')),
    );

    $rows = array_map(
        static fn (TaxBreakdown $row): string => $row->rate->percentage()->value().':'.$row->net->minor().'/'.$row->tax->minor().'/'.$row->gross->minor(),
        $summary->perRate(),
    );

    expect($rows)->toBe(['0:1000/0/1000', '10:1000/100/1100', '20:666/134/800'])
        ->and($summary->perRate()[2]->rate->label())->toBe('standard')
        ->and($summary->net()->minor())->toBe('2666')
        ->and($summary->tax()->minor())->toBe('234')
        ->and($summary->gross()->minor())->toBe('2900');
});

it('refuses mixed currencies and has no totals when empty', function (): void {
    expect(fn () => TaxSummary::of(
        TaxRate::of(20)->breakdownFromNet(Money::ofMinor(1, 'EUR')),
        TaxRate::of(20)->breakdownFromNet(Money::ofMinor(1, 'USD')),
    )->perRate())->toThrow(CurrencyMismatch::class)
        ->and(TaxSummary::of()->perRate())->toBe([])
        ->and(fn () => TaxSummary::of()->net())->toThrow(EmptyMoneyCollection::class);
});
