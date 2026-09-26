<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Tax\TaxRate;

it('keeps net + tax === gross on seeded amounts of 1 to 38 digits', function (): void {
    mt_srand(10_000);

    for ($i = 0; $i < 10_000; $i++) {
        $amount = Money::ofMinor(randomInteger(38), 'EUR');
        $rate = TaxRate::fromBasisPoints(mt_rand(0, 3000));
        $mode = RoundingMode::cases()[mt_rand(0, 7)];

        foreach ([$rate->breakdownFromNet($amount, $mode), $rate->breakdownFromGross($amount, $mode)] as $breakdown) {
            expect($breakdown->net->add($breakdown->tax)->minor())->toBe($breakdown->gross->minor());
        }
    }
});
