<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Support\RoundingModes;

it('maps every native mode to its snake_case name and back', function (RoundingMode $mode): void {
    expect(RoundingModes::fromValue(RoundingModes::toValue($mode), 'x'))->toBe($mode);
})->with(RoundingMode::cases());

it('uses the documented vocabulary', function (): void {
    expect(RoundingModes::toValue(RoundingMode::HalfAwayFromZero))->toBe('half_away_from_zero')
        ->and(RoundingModes::toValue(RoundingMode::HalfEven))->toBe('half_even')
        ->and(RoundingModes::fromValue(RoundingMode::HalfOdd, 'x'))->toBe(RoundingMode::HalfOdd);
});

it('reads a config key', function (): void {
    config(['money.test_rounding' => 'half_even']);

    expect(RoundingModes::fromConfig('money.test_rounding'))->toBe(RoundingMode::HalfEven);
});

it('refuses ambiguous aliases and garbage, naming the key', function (mixed $value): void {
    RoundingModes::fromValue($value, 'credits.rounding');
})->throws(InvalidMoneyConfiguration::class, 'credits.rounding')->with(['half_up', 'HalfEven', null, 1, [[]]]);

it('reads a blank value as not set: the default, or a throw when there is none', function (?string $value): void {
    expect(RoundingModes::fromValue($value, 'credits.rounding', RoundingMode::HalfEven))->toBe(RoundingMode::HalfEven)
        ->and(fn () => RoundingModes::fromValue($value, 'credits.rounding'))
        ->toThrow(InvalidMoneyConfiguration::class, '[credits.rounding] must name a rounding mode (e.g. half_even, half_away_from_zero), [null] given.');
})->with(['absent' => null, 'empty' => '', 'whitespace' => '  ']);

it('never lets a default stand in for junk', function (): void {
    RoundingModes::fromValue('half_up', 'credits.rounding', RoundingMode::HalfEven);
})->throws(InvalidMoneyConfiguration::class, '[half_up] given');
