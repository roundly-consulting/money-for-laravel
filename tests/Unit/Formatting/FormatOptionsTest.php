<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Contracts\MoneyFormatter;
use RoundlyConsulting\Money\Enums\CurrencyDisplay;
use RoundlyConsulting\Money\Enums\FormatterDriver;
use RoundlyConsulting\Money\Exceptions\FormatterUnavailable;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Formatting\DecimalMoneyFormatter;
use RoundlyConsulting\Money\Formatting\FormatOptions;
use RoundlyConsulting\Money\MoneyServiceProvider;

it('has named presets', function (): void {
    expect(FormatOptions::code()->display)->toBe(CurrencyDisplay::Code)
        ->and(FormatOptions::none()->display)->toBe(CurrencyDisplay::None)
        ->and(FormatOptions::whole()->trimTrailingZeros)->toBeTrue()
        ->and((new FormatOptions)->display)->toBe(CurrencyDisplay::Symbol)
        ->and((new FormatOptions)->fractionDigits)->toBeNull()
        ->and((new FormatOptions)->grouping)->toBeTrue()
        ->and((new FormatOptions)->rounding)->toBeNull();
});

it('resolves the formatter driver', function (): void {
    config(['money.formatting.driver' => 'auto']);
    expect(MoneyServiceProvider::formatterDriver(true))->toBe(FormatterDriver::Intl)
        ->and(MoneyServiceProvider::formatterDriver(false))->toBe(FormatterDriver::Decimal);

    config(['money.formatting.driver' => 'decimal']);
    expect(MoneyServiceProvider::formatterDriver(true))->toBe(FormatterDriver::Decimal);

    config(['money.formatting.driver' => 'intl']);
    expect(MoneyServiceProvider::formatterDriver(true))->toBe(FormatterDriver::Intl)
        ->and(fn () => MoneyServiceProvider::formatterDriver(false))->toThrow(FormatterUnavailable::class);

    config(['money.formatting.driver' => 'icu']);
    expect(fn () => MoneyServiceProvider::formatterDriver(true))->toThrow(InvalidMoneyConfiguration::class);
});

it('binds the decimal formatter when configured', function (): void {
    config(['money.formatting.driver' => 'decimal']);
    app()->forgetInstance(MoneyFormatter::class);

    expect(app(MoneyFormatter::class))->toBeInstanceOf(DecimalMoneyFormatter::class);
});

it('falls back to the decimal formatter without intl', function (): void {
    expect(app(MoneyFormatter::class))->toBeInstanceOf(DecimalMoneyFormatter::class);
})->skip(fn (): bool => extension_loaded('intl'), 'intl is loaded — covered on the no-intl leg');
