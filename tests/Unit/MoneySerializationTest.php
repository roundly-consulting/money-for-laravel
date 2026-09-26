<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Exceptions\RoundingNecessary;
use RoundlyConsulting\Money\Exceptions\UnknownCurrency;
use RoundlyConsulting\Money\Money;

it('serialises minor as a JSON string', function (): void {
    $money = Money::ofMinor(1050, 'EUR');

    expect($money->toArray())->toBe(['minor' => '1050', 'decimal' => '10.50', 'currency' => 'EUR'])
        ->and(json_encode($money))->toBe('{"minor":"1050","decimal":"10.50","currency":"EUR"}');
});

it('round-trips through arrays, JSON and serialize()', function (string $minor): void {
    $money = Money::ofMinor($minor, 'EUR');

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) json_encode($money), true, flags: JSON_THROW_ON_ERROR);

    expect(Money::fromArray($money->toArray())->equals($money))->toBeTrue()
        ->and(Money::fromArray($decoded)->equals($money))->toBeTrue()
        ->and(unserialize(serialize($money)))->toEqual($money);
})->with(['0', '-50', '1050', '92233720368547758070', str_repeat('9', 38)]);

it('accepts minor-only, decimal-only and int payloads', function (): void {
    expect(Money::fromArray(['minor' => 1050, 'currency' => 'EUR'])->minor())->toBe('1050')
        ->and(Money::fromArray(['decimal' => '10.5', 'currency' => 'EUR'])->minor())->toBe('1050')
        ->and(Money::fromArray(['minor' => '5', 'currency' => Currency::of('JPY')])->currency()->code)->toBe('JPY');
});

it('refuses tampered, float and incomplete payloads', function (array $payload, string $exception): void {
    expect(fn () => Money::fromArray($payload))->toThrow($exception);
})->with([
    'inconsistent' => [['minor' => '1050', 'decimal' => '10.51', 'currency' => 'EUR'], InvalidAmount::class],
    'float minor' => [['minor' => 1.0e20, 'currency' => 'EUR'], InvalidAmount::class],
    'bool minor' => [['minor' => true, 'currency' => 'EUR'], InvalidAmount::class],
    'int decimal' => [['decimal' => 10, 'currency' => 'EUR'], InvalidAmount::class],
    'no amount' => [['currency' => 'EUR'], InvalidAmount::class],
    'no currency' => [['minor' => '1'], InvalidAmount::class],
    'bad currency type' => [['minor' => '1', 'currency' => 978], InvalidAmount::class],
    'unknown currency' => [['minor' => '1', 'currency' => 'XYZ'], UnknownCurrency::class],
    'over-precise decimal' => [['decimal' => '10.505', 'currency' => 'EUR'], RoundingNecessary::class],
]);
