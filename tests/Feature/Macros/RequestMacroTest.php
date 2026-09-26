<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Exceptions\RoundingNecessary;

function jsonRequest(array $payload): Request
{
    return Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($payload));
}

it('turns JSON floats into exact money (the ceil(float * 100) bug)', function (float $input, string $minor): void {
    expect(jsonRequest(['price' => $input])->money('price', 'EUR')?->minor())->toBe($minor);
})->with([[1.1, '110'], [2.2, '220'], [19.99, '1999'], [0.1, '10']]);

it('accepts ints and decimal strings', function (): void {
    expect(jsonRequest(['price' => 12])->money('price', 'EUR')?->minor())->toBe('1200')
        ->and(Request::create('/', 'POST', ['price' => ' 12.50 '])->money('price', Currency::of('USD'))?->minor())->toBe('1250')
        ->and(Request::create('/', 'POST', ['price' => '12345678901234567.89'])->money('price', 'EUR')?->minor())->toBe('1234567890123456789');
});

it('resolves the currency from the argument, an input key or the default', function (): void {
    $request = Request::create('/', 'POST', ['price' => '1', 'currency' => 'CZK']);

    expect($request->money('price', 'EUR')?->currency()->code)->toBe('EUR')
        ->and($request->money('price', currencyKey: 'currency')?->currency()->code)->toBe('CZK')
        ->and($request->money('price')?->currency()->code)->toBe('EUR')
        ->and($request->money('price', '', 'missing')?->currency()->code)->toBe('EUR');
});

it('returns null for missing or blank input', function (): void {
    expect(Request::create('/', 'POST', [])->money('price', 'EUR'))->toBeNull()
        ->and(Request::create('/', 'POST', ['price' => '  '])->money('price', 'EUR'))->toBeNull();
});

it('throws on malformed or over-precise input', function (Request $request, string $exception): void {
    expect(fn () => $request->money('price', 'EUR'))->toThrow($exception);
})->with([
    'exponent' => [fn () => Request::create('/', 'POST', ['price' => '1e3']), InvalidAmount::class],
    'array' => [fn () => Request::create('/', 'POST', ['price' => ['1']]), InvalidAmount::class],
    'bool' => [fn () => jsonRequest(['price' => true]), InvalidAmount::class],
    'float noise' => [fn () => jsonRequest(['price' => 0.1 + 0.2]), RoundingNecessary::class],
    'huge float' => [fn () => jsonRequest(['price' => 1e20]), InvalidAmount::class],
]);
