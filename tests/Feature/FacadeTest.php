<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Facades\Currencies;
use RoundlyConsulting\Money\Facades\Exchange;

it('pins the Exchange facade to its manager, its fake and every action', function (): void {
    expect(Exchange::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

// Currencies fronts an in-memory, deterministic registry: nothing to fake, and the three
// actions all live behind Exchange::rates().
it('pins the Currencies facade to its registry contract', function (): void {
    expect(Currencies::class)->toDocumentItsRoot();
});
