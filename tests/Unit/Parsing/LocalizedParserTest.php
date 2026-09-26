<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exceptions\RoundingNecessary;
use RoundlyConsulting\Money\Facades\Currencies;
use RoundlyConsulting\Money\Money;

beforeEach(function (): void {
    if (! extension_loaded('intl')) {
        $this->markTestSkipped('locale symbols come from ext-intl; the no-intl leg runs the fallback cases');
    }
});

it('parses localized input', function (string $input, ?string $currency, string $locale, string $expected): void {
    expect((string) Money::parse($input, $currency, $locale))->toBe($expected);
})->with([
    'plain' => ['10.50', 'EUR', 'en', '10.50 EUR'],
    'sk with symbol' => ['1 234,50 €', null, 'sk', '1234.50 EUR'],
    'sk NBSP' => ["1\u{00A0}234,50\u{00A0}€", null, 'sk', '1234.50 EUR'],
    'sk NNBSP' => ["1\u{202F}234,50", 'EUR', 'sk', '1234.50 EUR'],
    'de_CH apostrophe' => ["1'234.50", 'CHF', 'de_CH', '1234.50 CHF'],
    'de_CH right quote' => ["CHF 1\u{2019}234.50", null, 'de_CH', '1234.50 CHF'],
    'en grouping' => ['$1,234,567.89', 'USD', 'en', '1234567.89 USD'],
    'code prefix' => ['EUR 10', null, 'en', '10.00 EUR'],
    'code suffix lower' => ['10 eur', null, 'en', '10.00 EUR'],
    'leading minus' => ['-10.5', 'EUR', 'en', '-10.50 EUR'],
    'minus before symbol' => ['-€10.50', null, 'en', '-10.50 EUR'],
    'trailing minus' => ['10.50-', 'EUR', 'en', '-10.50 EUR'],
    'unicode minus' => ["\u{2212}10.50", 'EUR', 'en', '-10.50 EUR'],
    'plus' => ['+10', 'EUR', 'en', '10.00 EUR'],
    'parentheses' => ['(1,234.50)', 'EUR', 'en', '-1234.50 EUR'],
    'parentheses and symbol' => ['(€1,234.50)', null, 'en', '-1234.50 EUR'],
    'indian grouping' => ['12,34,567.50', 'INR', 'hi_IN', '1234567.50 INR'],
    'bidi marks' => ["\u{200F}\u{200E}-1,234.50\u{00A0}€", null, 'ar', '-1234.50 EUR'],
    'default currency' => ['10', null, 'en', '10.00 EUR'],
    'symbol matching the argument' => ['$10', 'USD', 'en', '10.00 USD'],
    'JPY' => ['¥1,234', null, 'en', '1234 JPY'],
]);

it('refuses what it cannot parse unambiguously', function (string $input, ?string $currency, string $locale, string $exception): void {
    expect(fn () => Money::parse($input, $currency, $locale))->toThrow($exception);
})->with([
    'mismatch' => ['€10', 'USD', 'en', CurrencyMismatch::class],
    'misplaced grouping' => ['1.23.4', 'EUR', 'en', InvalidAmount::class],
    'grouping in fraction' => ['1.234,5', 'EUR', 'en', InvalidAmount::class],
    'short group' => ['1.23,5', 'EUR', 'de', InvalidAmount::class],
    'bad group' => ['12,34', 'EUR', 'en', InvalidAmount::class],
    'mixed grouping' => ['1,234 567.00', 'EUR', 'en', InvalidAmount::class],
    'two decimal separators' => ['1.2.3', 'EUR', 'de_CH', InvalidAmount::class],
    'precision' => ['10.555', 'EUR', 'en', RoundingNecessary::class],
    'no digits' => ['€', null, 'en', InvalidAmount::class],
    'letters inside' => ['1o0', 'EUR', 'en', InvalidAmount::class],
    'unknown symbol' => ['¤10', null, 'en', InvalidAmount::class],
    'currency both sides' => ['EUR 10 €', null, 'en', InvalidAmount::class],
    'unbalanced parentheses' => ['(10', 'EUR', 'en', InvalidAmount::class],
    'two signs' => ['--10', 'EUR', 'en', InvalidAmount::class],
    'minus and parentheses' => ['(-10)', 'EUR', 'en', InvalidAmount::class],
    'too long' => [str_repeat('1', 129), 'EUR', 'en', InvalidAmount::class],
    'bad locale' => ['10', 'EUR', 'x', InvalidMoneyConfiguration::class],
]);

it('resolves registered custom symbols and codes', function (): void {
    config(['money.schema.currency_length' => 10]);
    Currencies::register(Currency::custom('PTS', 0, 'Points', 'pts'));

    expect((string) Money::parse('1,200 pts', locale: 'en'))->toBe('1200 PTS')
        ->and((string) Money::parse('PTS 5', locale: 'en'))->toBe('5 PTS')
        ->and((string) Money::parse('5', Currency::of('PTS'), 'en'))->toBe('5 PTS');
});

it('refuses a symbol several registered currencies share', function (): void {
    config(['money.schema.currency_length' => 10]);
    Currencies::register(Currency::custom('GAMEUSD', 2, 'Game dollars', '$'));

    expect((string) Money::parse('$10', 'USD', 'en'))->toBe('10.00 USD')
        ->and(fn () => Money::parse('$10', locale: 'en'))->toThrow(InvalidAmount::class, 'several currencies');
});

it('accepts an unregistered currency argument by its own symbol', function (): void {
    $gems = Currency::custom('GEM', 0, 'Gems', 'gems');

    expect((string) Money::parse('12 gems', $gems, 'en'))->toBe('12 GEM');
});
