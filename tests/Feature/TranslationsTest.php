<?php

declare(strict_types=1);

it('ships en and sk validation messages with identical keys', function (): void {
    $en = require __DIR__.'/../../resources/lang/en/validation.php';
    $sk = require __DIR__.'/../../resources/lang/sk/validation.php';

    expect(array_keys($en))->toBe(['amount', 'precision', 'too_large', 'min', 'max', 'positive', 'negative', 'currency'])
        ->and(array_keys($sk))->toBe(array_keys($en));

    foreach ($en as $key => $message) {
        preg_match_all('/:\w+/', $message, $enPlaceholders);
        preg_match_all('/:\w+/', $sk[$key], $skPlaceholders);

        expect($skPlaceholders[0])->toEqualCanonicalizing($enPlaceholders[0]);
    }
});

it('loads the translations under the money namespace', function (): void {
    expect(__('money::validation.currency'))->toBe('The :attribute field must be a supported currency.');

    app()->setLocale('sk');

    expect(__('money::validation.currency'))->toBe('Pole :attribute musí obsahovať podporovanú menu.');
});
