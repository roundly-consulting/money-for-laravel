<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Tests\Fixtures\Models\Product;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * @return array<string, array{type_name: string, type: string, nullable: bool}>
 */
function productColumns(): array
{
    $columns = [];

    foreach (Schema::getColumns('money_products') as $column) {
        $columns[$column['name']] = $column;
    }

    return $columns;
}

it('emits a signed decimal(38,0) amount column per driver', function (): void {
    $column = productColumns()['price'];

    match (DriverMatrix::driver()) {
        'pgsql' => expect($column['type'])->toBe('numeric(38,0)'),
        'mysql', 'mariadb' => expect($column['type'])->toBe('decimal(38,0)'),
        default => expect($column['type_name'])->toBe('numeric'),
    };

    expect($column['nullable'])->toBeTrue();
});

it('emits currency columns of the configured length, the shared one once', function (): void {
    $columns = productColumns();

    expect($columns)->toHaveKeys(['price_currency', 'currency', 'shop_currency', 'legacy_currency'])
        ->not->toHaveKey('budget_currency')
        ->not->toHaveKey('compare_at_price_currency')
        ->not->toHaveKey('sale_price_currency');

    match (DriverMatrix::driver()) {
        'pgsql' => expect($columns['price_currency']['type'])->toBe('character varying(3)'),
        'mysql', 'mariadb' => expect($columns['price_currency']['type'])->toBe('varchar(3)'),
        default => expect($columns['price_currency']['type_name'])->toBe('varchar'),
    };
});

it('sorts, filters and sums money columns numerically', function (): void {
    foreach ([9, 10, 100, -5] as $minor) {
        Product::query()->create(['price' => Money::ofMinor($minor, 'EUR')]);
    }

    $sorted = Product::query()->orderBy('price')->get()->map(fn (Product $product): string => (string) $product->price?->minor())->all();

    expect($sorted)->toBe(['-5', '9', '10', '100'])
        ->and(Product::query()->where('price', '>', Money::ofMinor(9, 'EUR')->minor())->count())->toBe(2);

    $sum = Product::query()->where('price_currency', 'EUR')->sum('price');

    expect(Money::ofMinor(is_int($sum) ? $sum : explode('.', (string) $sum)[0], 'EUR')->minor())->toBe('114');
});

it('sums beyond int64 exactly on real engines', function (): void {
    Product::query()->create(['price' => Money::ofMinor(PHP_INT_MAX, 'EUR')]);
    Product::query()->create(['price' => Money::ofMinor(PHP_INT_MAX, 'EUR')]);

    $sum = (string) Product::query()->sum('price');

    expect(Money::ofMinor(explode('.', $sum)[0], 'EUR')->minor())->toBe('18446744073709551614');
})->skip(fn (): bool => DriverMatrix::driver() === 'sqlite', 'sqlite sums raise integer overflow beyond int64');

/**
 * 2^53 and 2^53 + 1 minor units: a double cannot tell them apart (0.009 ETH in wei).
 *
 * @return array{0: Product, 1: Product}
 */
function productsBeyondDoublePrecision(): array
{
    return [
        Product::query()->create(['price' => Money::ofMinor('9007199254740992', 'EUR')]),
        Product::query()->create(['price' => Money::ofMinor('9007199254740993', 'EUR')]),
    ];
}

// A bound string must not be compared as a double: pgsql coerces it to numeric, sqlite applies
// the column affinity, MySQL 8 converts it to the DECIMAL column's type.
it('filters beyond double precision exactly with a plain where and a bound minor()', function (): void {
    [$low, $high] = productsBeyondDoublePrecision();

    expect(Product::query()->where('price', '=', Money::ofMinor('9007199254740993', 'EUR')->minor())->pluck('id')->all())->toBe([$high->id])
        ->and(Product::query()->where('price', '<', Money::ofMinor('9007199254740993', 'EUR')->minor())->pluck('id')->all())->toBe([$low->id])
        ->and(Product::query()->where('price', '>', Money::ofMinor('9007199254740992', 'EUR')->minor())->pluck('id')->all())->toBe([$high->id]);
});

// Emulated prepares inline a quoted literal; MySQL converts that to the column type too.
it('compares a decimal column with a string literal exactly on MySQL', function (): void {
    [, $high] = productsBeyondDoublePrecision();

    expect(Product::query()->whereRaw("price = '9007199254740993'")->pluck('id')->all())->toBe([$high->id]);
})->skip(fn (): bool => ! in_array(DriverMatrix::driver(), ['mysql', 'mariadb'], true), 'a MySQL/MariaDB-only behaviour');
