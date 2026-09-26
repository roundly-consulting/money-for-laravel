<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Money\Support\MoneyBlueprint;

function blueprintColumns(Blueprint $table): array
{
    $columns = [];

    foreach ($table->getAddedColumns() as $column) {
        $columns[(string) $column->get('name')] = $column->getAttributes();
    }

    return $columns;
}

it('registers the three macros', function (): void {
    foreach (MoneyBlueprint::MACROS as $macro) {
        expect(Blueprint::hasMacro($macro))->toBeTrue();
    }
});

it('keeps the frozen macro contract callable with named arguments', function (): void {
    $table = new Blueprint(Schema::getConnection(), 'contract');

    $amount = $table->money('price');
    $table->money(column: 'compare_at_price', currency: 'currency', nullable: true);
    $table->money('sale_price', currency: 'currency', nullable: true);
    $table->money('budget', currency: false);
    $table->moneyJson(column: 'snapshot', nullable: true);
    $table->currencyCode(column: 'shop_currency', nullable: true);

    $columns = blueprintColumns($table);

    expect($amount)->toBeInstanceOf(ColumnDefinition::class)
        ->and($amount->get('name'))->toBe('price')
        ->and($columns['price'])->toMatchArray(['type' => 'decimal', 'total' => 38, 'places' => 0])
        ->and($columns['price_currency'])->toMatchArray(['type' => 'string', 'length' => 3])
        ->and($columns['compare_at_price']['nullable'] ?? false)->toBeTrue()
        ->and($columns['currency']['nullable'] ?? false)->toBeTrue()
        ->and(array_keys($columns))->toBe([
            'price', 'price_currency', 'compare_at_price', 'currency', 'sale_price', 'budget', 'snapshot', 'shop_currency',
        ])
        ->and($columns['snapshot'])->toMatchArray(['type' => 'jsonb', 'nullable' => true])
        ->and($columns['budget']['nullable'] ?? false)->toBeFalse();
});

it('names the currency code column currency by default', function (): void {
    $table = new Blueprint(Schema::getConnection(), 'defaults');

    expect($table->currencyCode()->get('name'))->toBe('currency')
        ->and($table->currencyCode('code')->get('nullable'))->toBeNull();
});

it('reads precision and currency length from config', function (): void {
    config(['money.schema.precision' => 65, 'money.schema.currency_length' => 10]);

    $table = new Blueprint(Schema::getConnection(), 'wide');
    $table->money('price');

    expect(blueprintColumns($table)['price'])->toMatchArray(['total' => 65])
        ->and(blueprintColumns($table)['price_currency'])->toMatchArray(['length' => 10]);
});

it('lets an existing host macro win', function (): void {
    $original = (new ReflectionClass(Blueprint::class))->getStaticPropertyValue('macros');

    try {
        Blueprint::flushMacros();
        Blueprint::macro('money', fn (): string => 'host');

        MoneyBlueprint::register();

        expect((new Blueprint(Schema::getConnection(), 't'))->money())->toBe('host')
            ->and(Blueprint::hasMacro('currencyCode'))->toBeTrue();
    } finally {
        Blueprint::flushMacros();

        foreach ($original as $name => $macro) {
            Blueprint::macro($name, $macro);
        }
    }
});
