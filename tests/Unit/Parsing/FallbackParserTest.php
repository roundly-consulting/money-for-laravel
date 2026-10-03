<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Money;

/*
 * Without ext-intl the parser takes its separators from money.formatting.fallback. These
 * run on the no-intl CI leg; with intl loaded they skip visibly.
 */

it('parses with the fallback separators', function (): void {
    expect((string) Money::parse('1,234.50 EUR', locale: 'sk'))->toBe('1234.50 EUR')
        ->and((string) Money::parse('(12.5)', 'USD'))->toBe('-12.50 USD');

    config(['money.formatting.fallback.decimal_separator' => ',', 'money.formatting.fallback.thousands_separator' => '.']);

    expect((string) Money::parse('1.234,50', 'EUR'))->toBe('1234.50 EUR')
        ->and(fn () => Money::parse('1.23,50', 'EUR'))->toThrow(InvalidAmount::class);
})->skip(fn (): bool => extension_loaded('intl'), 'intl is loaded — the fallback runs on the no-intl leg');

it('reads a blank fallback decimal separator as not set, so "." applies', function (): void {
    config(['money.formatting.fallback.decimal_separator' => '']);

    expect((string) Money::parse('10.50', 'EUR'))->toBe('10.50 EUR');
})->skip(fn (): bool => extension_loaded('intl'), 'intl is loaded — the fallback runs on the no-intl leg');

it('refuses a non-string fallback decimal separator', function (): void {
    config(['money.formatting.fallback.decimal_separator' => 5]);

    Money::parse('10', 'EUR');
})->throws(InvalidMoneyConfiguration::class)->skip(fn (): bool => extension_loaded('intl'), 'intl is loaded — the fallback runs on the no-intl leg');
