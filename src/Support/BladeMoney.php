<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Support;

use RoundlyConsulting\Money\Contracts\MoneyFormatter;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyValue;
use RoundlyConsulting\Money\Money;

/**
 * The runtime half of the `@money($price, 'sk')` Blade directive: escaped, locale-aware
 * output; `null` renders an empty string (nullable price columns are common).
 *
 * @internal
 */
final class BladeMoney
{
    public static function render(mixed $money, ?string $locale = null): string
    {
        if ($money === null) {
            return '';
        }

        if (! $money instanceof Money) {
            throw InvalidMoneyValue::notMoney('@money', $money);
        }

        return e(app(MoneyFormatter::class)->format($money, $locale));
    }

    public static function compile(string $expression): string
    {
        return '<?php echo \\'.self::class.'::render('.$expression.'); ?>';
    }
}
