<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Formatting;

use RoundlyConsulting\Money\Contracts\MoneyFormatter;
use RoundlyConsulting\Money\Enums\CurrencyDisplay;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Support\MoneyConfig;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * The deterministic formatter: no ICU, identical output on every host. The pattern and the
 * separators come from `money.formatting.fallback.*`; placeholders are `{sign}`, `{amount}`,
 * `{code}` and `{symbol}`. The locale is accepted (and validated) but does not change the
 * output.
 */
final class DecimalMoneyFormatter implements MoneyFormatter
{
    public function format(Money $money, ?string $locale = null, ?FormatOptions $options = null): string
    {
        Locales::resolve($locale);

        $options ??= new FormatOptions(
            display: Config::using(InvalidMoneyConfiguration::class)->enum('money.formatting.display', CurrencyDisplay::class),
        );

        $decimal = AmountDigits::resolve($money, $options);
        $negative = str_starts_with($decimal, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($decimal, '-'), 2), 2, null);

        $amount = $options->grouping
            ? AmountDigits::group($integer, MoneyConfig::thousandsSeparator())
            : $integer;

        if ($fraction !== null) {
            $amount .= MoneyConfig::decimalSeparator().$fraction;
        }

        $currency = $money->currency();

        [$code, $symbol] = match ($options->display) {
            CurrencyDisplay::Symbol => [$currency->code, $currency->displaySymbol()],
            CurrencyDisplay::Code => [$currency->code, $currency->code],
            CurrencyDisplay::None => ['', ''],
        };

        $rendered = strtr(MoneyConfig::fallbackPattern(), [
            '{sign}' => $negative ? '-' : '',
            '{amount}' => $amount,
            '{code}' => $code,
            '{symbol}' => $symbol,
        ]);

        return trim($rendered);
    }
}
