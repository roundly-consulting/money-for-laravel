<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Facades\Exchange;
use RoundlyConsulting\Money\Money;

/**
 * The README's ```php blocks, verbatim.
 *
 * @return list<string>
 */
function readmePhpBlocks(): array
{
    preg_match_all('/```php\n(.*?)```/s', (string) file_get_contents(__DIR__.'/../../README.md'), $matches);

    return $matches[1];
}

it('runs the README model example verbatim', function (): void {
    $blocks = readmePhpBlocks();

    expect($blocks)->toHaveCount(3);

    Exchange::fake(['EUR/USD' => '1.10', 'EUR/CZK' => '25.00']);

    // What a host app file already imports; the README leaves it out. The namespace keeps
    // the README's `Product` clear of every other class.
    $prelude = <<<'PHP'
        namespace RoundlyConsulting\Money\Tests\ReadmeExample;
        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;
        use RoundlyConsulting\Money\Money;
        PHP;

    $product = eval($prelude.$blocks[1].$blocks[2].'return $product;');

    expect($product->price)->toBeInstanceOf(Money::class)
        ->and((string) $product->price)->toBe('19.99 EUR')
        ->and((string) $product->price->convertTo('USD'))->toBe('21.99 USD')
        ->and((string) $product::query()->findOrFail($product->getKey())->price)->toBe('19.99 EUR');
});
