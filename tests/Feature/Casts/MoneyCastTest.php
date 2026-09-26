<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use RoundlyConsulting\Money\Casts\AsMoney;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyValue;
use RoundlyConsulting\Money\Exceptions\UnknownCurrency;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Tests\Fixtures\Models\Product;
use RoundlyConsulting\Testing\Database\DriverMatrix;

it('round-trips the default two-column mode', function (): void {
    $product = Product::query()->create(['price' => Money::ofMajor('19.99', 'EUR')]);

    expect($product->getAttributes()['price_currency'])->toBe('EUR');

    $fresh = Product::query()->findOrFail($product->id);

    expect($fresh->price)->toBeInstanceOf(Money::class)
        ->and((string) $fresh->price)->toBe('19.99 EUR')
        ->and($fresh->price->minor())->toBe('1999');
});

it('maps null to null and leaves the currency column alone', function (): void {
    $product = Product::query()->create(['price' => Money::ofMinor(100, 'USD')]);
    $product->price = null;
    $product->save();

    $fresh = Product::query()->findOrFail($product->id);

    expect($fresh->price)->toBeNull()
        ->and($fresh->getAttributes()['price_currency'])->toBe('USD');
});

it('writes the default mode currency column even when it changes', function (): void {
    $product = Product::query()->create(['price' => Money::ofMinor(100, 'USD')]);
    $product->update(['price' => Money::ofMinor(100, 'EUR')]);

    expect((string) Product::query()->findOrFail($product->id)->price)->toBe('1.00 EUR');
});

it('shares a named currency column and refuses to re-denominate it', function (): void {
    $product = Product::query()->create([
        'compare_at_price' => Money::ofMinor(1200, 'EUR'),
        'sale_price' => Money::ofMinor(1000, 'EUR'),
    ]);

    $fresh = Product::query()->findOrFail($product->id);

    expect((string) $fresh->compare_at_price)->toBe('12.00 EUR')
        ->and((string) $fresh->sale_price)->toBe('10.00 EUR')
        ->and($fresh->currency?->code)->toBe('EUR')
        ->and(fn () => $fresh->sale_price = Money::ofMinor(1000, 'USD'))->toThrow(CurrencyMismatch::class, 'currency');
});

it('re-denominates on purpose when the currency column is set first', function (): void {
    $product = Product::query()->create(['sale_price' => Money::ofMinor(1000, 'EUR')]);

    $product->fill(['currency' => 'USD', 'sale_price' => Money::ofMinor(900, 'USD')])->save();

    expect((string) Product::query()->findOrFail($product->id)->sale_price)->toBe('9.00 USD');
});

it('adopts a null named column', function (): void {
    $product = new Product;
    $product->sale_price = Money::ofMinor(5, 'CZK');

    expect($product->getAttributes())->toMatchArray(['sale_price' => '5', 'currency' => 'CZK']);
});

it('stores fixed, config and attribute currencies with the amount only', function (): void {
    $product = Product::query()->create([
        'shop_currency' => 'CZK',
        'budget' => Money::ofMinor(500, 'EUR'),
        'store_credit' => Money::ofMinor(700, 'EUR'),
        'total' => Money::ofMinor(900, 'CZK'),
    ]);

    $fresh = Product::query()->findOrFail($product->id);

    expect((string) $fresh->budget)->toBe('5.00 EUR')
        ->and((string) $fresh->store_credit)->toBe('7.00 EUR')
        ->and((string) $fresh->total)->toBe('9.00 CZK');

    config(['money.default_currency' => 'USD']);

    expect((string) Product::query()->findOrFail($product->id)->store_credit)->toBe('7.00 USD');
});

it('asserts the currency of single-column modes', function (): void {
    $product = new Product(['shop_currency' => 'CZK']);

    expect(fn () => $product->budget = Money::ofMinor(1, 'USD'))->toThrow(CurrencyMismatch::class)
        ->and(fn () => $product->store_credit = Money::ofMinor(1, 'USD'))->toThrow(CurrencyMismatch::class)
        ->and(fn () => $product->total = Money::ofMinor(1, 'EUR'))->toThrow(CurrencyMismatch::class);
});

it('refuses raw numbers and unregistered currencies', function (): void {
    $product = new Product;

    expect(fn () => $product->price = 1050)->toThrow(InvalidMoneyValue::class, 'Money instance')
        ->and(fn () => $product->price = '10.50')->toThrow(InvalidMoneyValue::class)
        ->and(fn () => $product->price = Money::ofMinor(1, Currency::custom('PTS', 0)))->toThrow(InvalidMoneyValue::class, 'registered')
        ->and(fn () => $product->price = Money::ofMinor(1, new Currency('EUR', 3, 'Euro')))->toThrow(InvalidMoneyValue::class, 'registered')
        ->and(fn () => $product->snapshot = Money::ofMinor(1, Currency::custom('PTS', 0)))->toThrow(InvalidMoneyValue::class)
        ->and(fn () => $product->snapshot = 5)->toThrow(InvalidMoneyValue::class);
});

it('refuses corrupt and currency-less stored values', function (array $raw, string $attribute, string $exception): void {
    $product = (new Product)->setRawAttributes($raw);

    expect(fn () => $product->{$attribute})->toThrow($exception);
})->with([
    'float' => [['price' => 10.5, 'price_currency' => 'EUR'], 'price', InvalidMoneyValue::class],
    'fraction' => [['price' => '10.5', 'price_currency' => 'EUR'], 'price', InvalidMoneyValue::class],
    'too long' => [['price' => str_repeat('9', 66), 'price_currency' => 'EUR'], 'price', InvalidMoneyValue::class],
    'array' => [['price' => [1], 'price_currency' => 'EUR'], 'price', InvalidMoneyValue::class],
    'missing currency' => [['price' => '10', 'price_currency' => null], 'price', InvalidMoneyValue::class],
    'unknown currency' => [['price' => '10', 'price_currency' => 'XYZ'], 'price', UnknownCurrency::class],
    'no attribute currency' => [['total' => '10'], 'total', InvalidMoneyValue::class],
    'json not object' => [['snapshot' => '"x"'], 'snapshot', InvalidMoneyValue::class],
    'json float minor' => [['snapshot' => '{"minor":1e30,"currency":"EUR"}'], 'snapshot', InvalidMoneyValue::class],
]);

it('reads ints, integer strings and leading zeros alike', function (): void {
    expect((new Product)->setRawAttributes(['price' => 1050, 'price_currency' => 'EUR'])->price?->minor())->toBe('1050')
        ->and((new Product)->setRawAttributes(['price' => '01050', 'price_currency' => 'eur'])->price?->minor())->toBe('1050');
});

it('does not dirty an equal amount across int and string originals', function (int|string $original): void {
    $product = (new Product)->setRawAttributes(['price' => $original, 'price_currency' => 'EUR'], sync: true);

    $product->price = Money::ofMinor(1050, 'EUR');

    expect($product->isDirty('price'))->toBeFalse();

    $product->price = Money::ofMinor(1051, 'EUR');

    expect($product->isDirty('price'))->toBeTrue();

    $product->price = null;

    expect($product->isDirty('price'))->toBeTrue();
})->with([1050, '1050', '01050']);

it('treats unreadable originals as changed', function (): void {
    $product = (new Product)->setRawAttributes(['price' => 10.5, 'price_currency' => 'EUR'], sync: true);
    $product->price = Money::ofMinor(1050, 'EUR');

    expect($product->isDirty('price'))->toBeTrue();
});

it('serialises to the money array shape', function (): void {
    $product = new Product([
        'price' => Money::ofMinor(1050, 'EUR'),
        'snapshot' => Money::ofMinor(5, 'JPY'),
    ]);

    expect($product->toArray()['price'])->toBe(['minor' => '1050', 'decimal' => '10.50', 'currency' => 'EUR'])
        ->and($product->toArray()['snapshot'])->toBe(['minor' => '5', 'decimal' => '5', 'currency' => 'JPY'])
        ->and($product->toArray()['budget'] ?? null)->toBeNull();
});

it('refuses amounts wider than the configured column precision', function (): void {
    config(['money.schema.precision' => 19]);

    $product = new Product;
    $product->price = Money::ofMinor(str_repeat('9', 20), 'EUR');
})->throws(InvalidMoneyValue::class, '20 digits');

it('fails loud on an invalid precision setting', function (): void {
    config(['money.schema.precision' => 70]);

    $product = new Product;
    $product->price = Money::ofMinor(1, 'EUR');
})->throws(InvalidMoneyConfiguration::class);

it('refuses amounts beyond int64 on sqlite instead of storing a REAL', function (): void {
    $product = new Product;
    $product->price = Money::ofMinor('9223372036854775808', 'EUR');
})->throws(InvalidMoneyValue::class, 'sqlite')
    ->skip(fn (): bool => DriverMatrix::driver() !== 'sqlite', 'sqlite-only guard');

it('round-trips 38-digit amounts exactly on real engines', function (): void {
    $wide = Money::ofMinor(str_repeat('9', 38), 'EUR');
    $tenMax = Money::ofMinor('92233720368547758070', 'EUR');

    $a = Product::query()->create(['price' => $wide, 'snapshot' => $wide]);
    $b = Product::query()->create(['price' => $tenMax->negate()]);

    expect(Product::query()->findOrFail($a->id)->price?->equals($wide))->toBeTrue()
        ->and(Product::query()->findOrFail($a->id)->snapshot?->equals($wide))->toBeTrue()
        ->and(Product::query()->findOrFail($b->id)->price?->equals($tenMax->negate()))->toBeTrue()
        ->and(Product::query()->findOrFail($b->id)->price?->add(Money::ofMinor(1, 'EUR'))->minor())->toBe('-92233720368547758069');
})->skip(fn (): bool => DriverMatrix::driver() === 'sqlite', 'sqlite is exact only within int64 — proven on pgsql/mysql');

it('keeps existing bigint columns working and lets the engine refuse int64 overflow', function (): void {
    $product = Product::query()->create(['legacy_amount' => Money::ofMinor(PHP_INT_MAX, 'EUR')]);

    expect(Product::query()->findOrFail($product->id)->legacy_amount?->minor())->toBe('9223372036854775807')
        ->and(fn () => Product::query()->create(['legacy_amount' => Money::ofMinor('9223372036854775808', 'EUR')]))
        ->toThrow(QueryException::class);
})->skip(fn (): bool => DriverMatrix::driver() === 'sqlite', 'on sqlite the range guard fires first');

it('stores JSON money with a string minor', function (): void {
    $product = Product::query()->create(['snapshot' => Money::ofMinor(1050, 'EUR')]);

    $raw = Product::query()->whereKey($product->id)->toBase()->value('snapshot');

    expect(json_decode((string) $raw, true))->toBe(['minor' => '1050', 'currency' => 'EUR'])
        ->and((string) Product::query()->findOrFail($product->id)->snapshot)->toBe('10.50 EUR')
        ->and((new Product)->setRawAttributes(['snapshot' => '{"minor":1050,"currency":"EUR"}'])->snapshot?->minor())->toBe('1050')
        ->and((new Product)->setRawAttributes(['snapshot' => '{"minor":"1050","decimal":"10.50","currency":"EUR"}'])->snapshot?->minor())->toBe('1050');

    $product->snapshot = null;
    expect($product->getAttributes()['snapshot'])->toBeNull();
});

it('rejects malformed cast declarations', function (array $arguments): void {
    AsMoney::castUsing($arguments);
})->throws(InvalidMoneyConfiguration::class)->with([
    [['nope', 'x']],
    [['fixed']],
    [['column', '']],
]);

it('builds cast declaration strings', function (): void {
    expect(AsMoney::currencyColumn('currency'))->toBe(AsMoney::class.':column,currency')
        ->and(AsMoney::fixedCurrency(' eur'))->toBe(AsMoney::class.':fixed,EUR')
        ->and(AsMoney::fixedCurrency(Currency::of('USD')))->toBe(AsMoney::class.':fixed,USD')
        ->and(AsMoney::configCurrency('shops.currency'))->toBe(AsMoney::class.':config,shops.currency')
        ->and(AsMoney::attributeCurrency('shop_currency'))->toBe(AsMoney::class.':attribute,shop_currency');
});

it('does not dirty JSON money the engine re-formatted', function (string $stored): void {
    // pgsql jsonb and MySQL JSON hand back {"minor": "1050", "currency": "EUR"} — spaced,
    // possibly re-ordered — so a byte comparison saw every read as a change.
    Product::query()->toBase()->insert(['snapshot' => $stored]);
    $product = Product::query()->firstOrFail();

    expect((string) $product->snapshot)->toBe('10.50 EUR')
        ->and($product->isDirty())->toBeFalse();

    $product->snapshot = Money::ofMinor(1050, 'EUR');

    expect($product->isDirty('snapshot'))->toBeFalse()
        ->and($product->save())->toBeTrue()
        ->and($product->wasChanged())->toBeFalse();

    $product->snapshot = Money::ofMinor(1051, 'EUR');
    expect($product->isDirty('snapshot'))->toBeTrue();

    $product->snapshot = Money::ofMinor(1050, 'USD');
    expect($product->isDirty('snapshot'))->toBeTrue();
})->with([
    'jsonb / MySQL spacing' => ['{"minor": "1050", "currency": "EUR"}'],
    'reordered keys' => ['{"currency":"EUR","minor":"1050"}'],
    'int minor, full shape' => ['{"minor":1050,"decimal":"10.50","currency":"EUR"}'],
]);
