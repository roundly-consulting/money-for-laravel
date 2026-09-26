<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Formatting\DecimalMoneyFormatter;
use RoundlyConsulting\Money\Formatting\FormatOptions;
use RoundlyConsulting\Money\Money;

it('renders the deterministic default pattern', function (): void {
    $formatter = new DecimalMoneyFormatter;

    expect($formatter->format(Money::ofMinor(6747, 'EUR'), 'sk'))->toBe('67.47 EUR')
        ->and($formatter->format(Money::ofMinor(-123456750, 'EUR')))->toBe('-1,234,567.50 EUR')
        ->and($formatter->format(Money::ofMinor(5, 'JPY')))->toBe('5 JPY')
        ->and($formatter->format(Money::ofMinor(str_repeat('9', 38), 'EUR'), options: new FormatOptions(grouping: false)))->toBe(str_repeat('9', 36).'.99 EUR');
});

it('honours every option', function (): void {
    $formatter = new DecimalMoneyFormatter;
    $money = Money::ofMinor(123400, 'EUR');

    expect($formatter->format($money, options: FormatOptions::whole()))->toBe('1,234 EUR')
        ->and($formatter->format($money, options: FormatOptions::none()))->toBe('1,234.00')
        ->and($formatter->format($money, options: FormatOptions::code()))->toBe('1,234.00 EUR')
        ->and($formatter->format($money, options: new FormatOptions(grouping: false)))->toBe('1234.00 EUR')
        ->and($formatter->format($money, options: new FormatOptions(fractionDigits: 0)))->toBe('1,234 EUR');
});

it('uses the configured pattern and separators', function (): void {
    config([
        'money.formatting.fallback.pattern' => '{sign}{symbol} {amount}',
        'money.formatting.fallback.decimal_separator' => ',',
        'money.formatting.fallback.thousands_separator' => '.',
    ]);

    $formatter = new DecimalMoneyFormatter;

    expect($formatter->format(Money::ofMinor(-123456, 'EUR')))->toBe('-€ 1.234,56')
        ->and($formatter->format(Money::ofMinor(100, Currency::custom('PTS', 0, 'P', 'pts'))))->toBe('pts 100')
        ->and($formatter->format(Money::ofMinor(100, 'EUR'), options: FormatOptions::code()))->toBe('EUR 1,00')
        ->and($formatter->format(Money::ofMinor(100, 'EUR'), options: FormatOptions::none()))->toBe('1,00');
});

it('is identical whatever the locale', function (): void {
    $formatter = new DecimalMoneyFormatter;

    expect($formatter->format(Money::ofMinor(1050, 'EUR'), 'en'))->toBe($formatter->format(Money::ofMinor(1050, 'EUR'), 'ja_JP'));
});
