<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Contracts\MoneyFormatter;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Enums\CurrencyDisplay;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Formatting\FormatOptions;
use RoundlyConsulting\Money\Formatting\IntlMoneyFormatter;
use RoundlyConsulting\Money\Money;

beforeEach(function (): void {
    if (! extension_loaded('intl')) {
        $this->markTestSkipped('ext-intl is not loaded (the no-intl leg covers the fallback).');
    }
});

it('formats per locale', function (string $locale, string $expected): void {
    expect(ws((new IntlMoneyFormatter)->format(Money::ofMinor(-123456750, 'EUR'), $locale)))->toBe($expected);
})->with([
    ['en_US', '-€1,234,567.50'],
    ['sk', '-1 234 567,50 €'],
    ['de_CH', 'EUR-1’234’567.50'],
    ['ja_JP', '-€1,234,567.50'],
    ['ar', '-1,234,567.50 €'],
    ['hi_IN', '-€12,34,567.50'],
]);

it('forces the ISO exponent digits over CLDR display digits', function (): void {
    $formatter = new IntlMoneyFormatter;

    expect(ws($formatter->format(Money::ofMinor(12345, 'HUF'), 'hu')))->toBe('123,45 Ft')
        ->and(ws($formatter->format(Money::ofMinor(1234, 'BHD'), 'en_US')))->toBe('BHD 1.234')
        ->and(ws($formatter->format(Money::ofMinor(1234, 'JPY'), 'en_US')))->toBe('¥1,234');
});

it('renders custom currencies with their own symbol', function (): void {
    $points = Currency::custom('PTS', 0, 'Points', 'pts');

    expect(ws((new IntlMoneyFormatter)->format(Money::ofMinor(1200, $points), 'en_US')))->toBe('pts 1,200')
        ->and(ws((new IntlMoneyFormatter)->format(Money::ofMinor(1200, Currency::custom('XP', 0)), 'en_US')))->toBe('XP 1,200');
});

it('formats amounts beyond float precision digit-exactly', function (): void {
    $formatter = new IntlMoneyFormatter;

    expect(ws($formatter->format(Money::ofMinor('1234567890123456789', 'USD'), 'en_US')))->toBe('$12,345,678,901,234,567.89')
        ->and(ws($formatter->format(Money::ofMinor('-1234567890123456789', 'EUR'), 'sk')))->toBe('-12 345 678 901 234 567,89 €')
        ->and(ws($formatter->format(Money::ofMinor('1234567890123456789', 'EUR'), 'hi_IN')))->toBe('€12,34,56,78,90,12,34,567.89')
        ->and(ws($formatter->format(Money::ofMinor('123456789012345678', 'EUR'), 'sk', FormatOptions::none())))->toBe('1 234 567 890 123 456,78')
        ->and(ws($formatter->format(Money::ofMinor(str_repeat('9', 38), 'EUR'), 'en_US', new FormatOptions(grouping: false))))->toBe('€'.str_repeat('9', 36).'.99');
});

it('honours every option', function (): void {
    $formatter = new IntlMoneyFormatter;

    expect(ws($formatter->format(Money::ofMinor(1000, 'EUR'), 'en_US', FormatOptions::whole())))->toBe('€10')
        ->and(ws($formatter->format(Money::ofMinor(1050, 'EUR'), 'en_US', FormatOptions::whole())))->toBe('€10.50')
        ->and(ws($formatter->format(Money::ofMinor(1000, 'EUR'), 'sk', FormatOptions::code())))->toBe('10,00 EUR')
        ->and(ws($formatter->format(Money::ofMinor(123456, 'EUR'), 'sk', FormatOptions::none())))->toBe('1 234,56')
        ->and(ws($formatter->format(Money::ofMinor(123456, 'EUR'), 'en_US', new FormatOptions(grouping: false))))->toBe('€1234.56')
        ->and(ws($formatter->format(Money::ofMinor(1255, 'EUR'), 'en_US', new FormatOptions(fractionDigits: 1))))->toBe('€12.6')
        ->and(ws($formatter->format(Money::ofMinor(1255, 'EUR'), 'en_US', new FormatOptions(fractionDigits: 1, rounding: RoundingMode::HalfEven))))->toBe('€12.6')
        ->and(ws($formatter->format(Money::ofMinor(1245, 'EUR'), 'en_US', new FormatOptions(fractionDigits: 1, rounding: RoundingMode::HalfEven))))->toBe('€12.4')
        ->and(ws($formatter->format(Money::ofMinor(1245, 'EUR'), 'en_US', new FormatOptions(fractionDigits: 4))))->toBe('€12.4500');
});

it('reads the display, locale and rounding defaults from config', function (): void {
    config(['money.formatting.display' => 'code', 'money.formatting.locale' => 'sk']);

    expect(ws(Money::ofMinor(1050, 'EUR')->format()))->toBe('10,50 EUR');

    config(['money.formatting.locale' => null]);
    app()->setLocale('de');

    expect(ws(Money::ofMinor(1050, 'EUR')->format()))->toBe('10,50 EUR');

    config(['money.rounding' => 'towards_zero', 'money.formatting.display' => 'symbol']);

    expect(ws(Money::ofMinor(1259, 'EUR')->format('en_US', new FormatOptions(fractionDigits: 1))))->toBe('€12.5');
});

it('rejects garbage locales before they reach ICU', function (string $locale): void {
    (new IntlMoneyFormatter)->format(Money::zero('EUR'), $locale);
})->throws(InvalidMoneyConfiguration::class)->with(['', 'e', '../etc', 'en US', 'en_US!']);

it('caches at most 64 formatters per worker', function (): void {
    $formatter = new IntlMoneyFormatter;
    $money = Money::ofMinor(1, 'EUR');

    for ($digits = 0; $digits < 80; $digits++) {
        $formatter->format($money, 'en', new FormatOptions(fractionDigits: $digits % 36, display: $digits < 36 ? CurrencyDisplay::Symbol : CurrencyDisplay::Code));
    }

    $formatter->format($money, 'en');
    $formatter->format($money, 'en');

    expect($formatter->cachedFormatters())->toBe(IntlMoneyFormatter::CACHE_SIZE);
});

it('is the formatter auto resolves to when intl is loaded', function (): void {
    expect(app(MoneyFormatter::class))->toBeInstanceOf(IntlMoneyFormatter::class);
});
