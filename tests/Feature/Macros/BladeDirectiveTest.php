<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;
use RoundlyConsulting\Money\Contracts\MoneyFormatter;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyValue;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Support\BladeMoney;

it('renders @money escaped in the current or given locale', function (): void {
    config(['money.formatting.driver' => 'decimal']);
    app()->forgetInstance(MoneyFormatter::class);

    expect(Blade::render('@money($price)', ['price' => Money::ofMinor(1050, 'EUR')]))->toBe('10.50 EUR')
        ->and(Blade::render("@money(\$price, 'sk')", ['price' => Money::ofMinor(-1050, 'EUR')]))->toBe('-10.50 EUR')
        ->and(Blade::render('@money($price)', ['price' => null]))->toBe('');
});

it('escapes the output', function (): void {
    config([
        'money.formatting.driver' => 'decimal',
        'money.formatting.fallback.pattern' => '<b>{amount}</b>',
    ]);
    app()->forgetInstance(MoneyFormatter::class);

    expect(Blade::render('@money($price)', ['price' => Money::ofMinor(1, 'EUR')]))->toBe('&lt;b&gt;0.01&lt;/b&gt;');
});

it('refuses anything that is not money', function (): void {
    expect(fn () => BladeMoney::render(10.5))->toThrow(InvalidMoneyValue::class)
        ->and(fn () => Blade::render('@money($price)', ['price' => 10.5]))->toThrow(ViewException::class, '[@money] expects a Money instance');
});

it('is registered when enabled', function (): void {
    expect(Blade::getCustomDirectives())->toHaveKey('money');
});

it('compiles to a call of the runtime renderer', function (): void {
    expect(BladeMoney::compile("\$price, 'sk'"))->toBe("<?php echo \\RoundlyConsulting\\Money\\Support\\BladeMoney::render(\$price, 'sk'); ?>");
});
