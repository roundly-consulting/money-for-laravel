<?php

declare(strict_types=1);

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Money\Casts\AsCurrency;
use RoundlyConsulting\Money\Casts\AsMoney;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Discounts\Discount;
use RoundlyConsulting\Money\Discounts\DiscountAllocator;
use RoundlyConsulting\Money\Http\Resources\MoneyResource;
use RoundlyConsulting\Money\Math\MinorUnits;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Percentage;
use RoundlyConsulting\Money\Support\RoundingModes;
use RoundlyConsulting\Money\Tax\TaxRate;

/**
 * money-for-laravel's own architecture rules (plan §9.4). Every scan asserts a non-zero
 * count first, so none of them can pass over nothing.
 */
const SRC = __DIR__.'/../../src';

/** @return array<string, string> relative path → source */
function sources(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SRC, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[substr($file->getPathname(), strlen(SRC) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($files);

    return $files;
}

/** @return list<class-string> */
function packageClasses(): array
{
    $classes = [];

    foreach (array_keys(sources()) as $path) {
        $class = 'RoundlyConsulting\\Money\\'.str_replace(['/', '.php'], ['\\', ''], $path);

        if (class_exists($class) || interface_exists($class) || enum_exists($class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

function typeString(?ReflectionType $type): string
{
    return $type === null ? '' : (string) $type;
}

/** @return list<string> "Class::method" code tokens, comments stripped */
function codeTokens(string $source): array
{
    return array_values(array_filter(
        token_get_all($source),
        static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true),
    ));
}

it('rule 1 — takes and returns no float outside the HTTP boundary', function (): void {
    $allowed = [
        'RoundlyConsulting\Money\Math\DecimalString::fromFloat',
        'RoundlyConsulting\Money\Rules\MoneyAmount::validate',
    ];

    $scanned = 0;
    $offenders = [];

    foreach (packageClasses() as $class) {
        if (str_starts_with($class, 'RoundlyConsulting\Money\PHPStan\\') || $class === 'RoundlyConsulting\Money\Support\MoneyMacros') {
            continue;
        }

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $scanned++;
            $types = [typeString($method->getReturnType()), ...array_map(static fn (ReflectionParameter $p): string => typeString($p->getType()), $method->getParameters())];

            foreach ($types as $type) {
                if (preg_match('/\bfloat\b/', $type) === 1 && ! in_array($class.'::'.$method->getName(), $allowed, true)) {
                    $offenders[] = $class.'::'.$method->getName();
                }
            }
        }
    }

    expect($scanned)->toBeGreaterThanOrEqual(150)
        ->and(array_unique($offenders))->toBe([]);
});

it('rule 2 — does no float math outside the two allow-listed files', function (): void {
    $allowed = ['Formatting/IntlMoneyFormatter.php', 'Math/DecimalString.php'];
    $banned = ['round', 'ceil', 'floor', 'floatval', 'number_format', 'fdiv'];
    $offenders = [];

    foreach (sources() as $path => $source) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        $tokens = codeTokens($source);

        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_DOUBLE_CAST) {
                $offenders[] = $path.': (float)';
            }

            $next = $tokens[$index + 1] ?? null;
            $previous = $tokens[$index - 1] ?? null;

            if (is_array($token) && $token[0] === T_STRING && in_array(strtolower($token[1]), $banned, true) && $next === '('
                && ! (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR], true))) {
                $offenders[] = $path.': '.$token[1].'(';
            }
        }
    }

    expect(count(sources()))->toBeGreaterThan(50)
        ->and($offenders)->toBe([]);
});

it('rule 3 — keeps value objects pure', function (): void {
    $pure = ['Money.php', 'Currency.php', 'Ratio.php', 'Percentage.php', 'MoneyBag.php', 'Exchange/ExchangeRate.php'];

    foreach (array_keys(sources()) as $path) {
        if (str_starts_with($path, 'Discounts/') || str_starts_with($path, 'Tax/')) {
            $pure[] = $path;
        }
    }

    $allowedContainer = ['Money::format', 'Money::convertTo', 'Money::parse', 'MoneyBag::total', 'Currency::of'];
    $checked = 0;

    foreach ($pure as $path) {
        $source = sources()[$path];
        $checked++;

        expect($source)->not->toContain('config(')
            ->not->toContain('env(')
            ->not->toContain('Illuminate\Support\Facades');

        $class = 'RoundlyConsulting\\Money\\'.str_replace(['/', '.php'], ['\\', ''], $path);

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class || $method->getFileName() === false) {
                continue;
            }

            $body = implode("\n", array_slice(explode("\n", $source), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

            if (str_contains($body, 'app(')) {
                expect($allowedContainer)->toContain(class_basename($class).'::'.$method->getName());
            }
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(14);
});

it('rule 4 — exposes no @internal class through a public signature', function (): void {
    $internal = [];

    foreach (packageClasses() as $class) {
        if (str_contains((string) (new ReflectionClass($class))->getDocComment(), '@internal')) {
            $internal[] = $class;
        }
    }

    expect(count($internal))->toBeGreaterThanOrEqual(10);

    $exempt = ['RoundlyConsulting\Money\Math\\', 'RoundlyConsulting\Money\Casts\\', 'RoundlyConsulting\Money\Exchange\Ecb\\'];

    foreach (packageClasses() as $class) {
        if (in_array($class, $internal, true) || array_filter($exempt, static fn (string $prefix): bool => str_starts_with($class, $prefix)) !== []) {
            continue;
        }

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $types = [typeString($method->getReturnType()), ...array_map(static fn (ReflectionParameter $p): string => typeString($p->getType()), $method->getParameters())];

            foreach ($internal as $hidden) {
                foreach ($types as $type) {
                    expect(str_contains($type, $hidden))->toBeFalse("{$class}::{$method->getName()} exposes {$hidden}");
                }
            }
        }
    }
});

/**
 * @return list<string> "name: type = default"
 */
function signature(string $class, string $method): array
{
    $reflection = new ReflectionMethod($class, $method);

    $parameters = array_map(static function (ReflectionParameter $parameter): string {
        $default = '';

        if ($parameter->isDefaultValueAvailable()) {
            $value = $parameter->getDefaultValue();
            $default = ' = '.($value instanceof UnitEnum ? $value::class.'::'.$value->name : var_export($value, true));
        }

        return ($parameter->isVariadic() ? '...' : '').$parameter->getName().': '.typeString($parameter->getType()).$default;
    }, $reflection->getParameters());

    return [...$parameters, 'return: '.typeString($reflection->getReturnType())];
}

it('rule 5 — pins the API the consumer packages build on', function (): void {
    $snapshot = [
        [Money::class, 'ofMinor', ['minor: string|int', 'currency: RoundlyConsulting\Money\Currency|string', 'return: self']],
        [Money::class, 'ofMajor', ['amount: string|int', 'currency: RoundlyConsulting\Money\Currency|string', 'rounding: ?RoundingMode = NULL', 'return: self']],
        [Money::class, 'ofScaled', ['value: string|int', 'scale: int', 'currency: RoundlyConsulting\Money\Currency|string', 'rounding: ?RoundingMode = NULL', 'return: self']],
        [Money::class, 'zero', ['currency: RoundlyConsulting\Money\Currency|string', 'return: self']],
        [Money::class, 'fromArray', ['data: array', 'return: self']],
        [Money::class, 'minor', ['return: string']],
        [Money::class, 'minorInt', ['return: int']],
        [Money::class, 'fitsInt', ['return: bool']],
        [Money::class, 'currency', ['return: RoundlyConsulting\Money\Currency']],
        [Money::class, 'toDecimal', ['trimTrailingZeros: bool = false', 'return: string']],
        [Money::class, 'toArray', ['return: array']],
        [Money::class, 'isPositive', ['return: bool']],
        [Money::class, 'isZero', ['return: bool']],
        [Money::class, 'isNegative', ['return: bool']],
        [Money::class, 'isGreaterThanOrEqualTo', ['other: self', 'return: bool']],
        [Money::class, 'isLessThanOrEqualTo', ['other: self', 'return: bool']],
        [Money::class, 'min', ['monies: iterable', 'return: self']],
        [Money::class, 'max', ['monies: iterable', 'return: self']],
        [Money::class, 'sum', ['monies: iterable', 'currencyIfEmpty: RoundlyConsulting\Money\Currency|string|null = NULL', 'return: self']],
        [Money::class, 'add', ['...others: self', 'return: self']],
        [Money::class, 'subtract', ['...others: self', 'return: self']],
        [Money::class, 'multiply', ['factor: RoundlyConsulting\Money\Ratio|string|int', 'rounding: RoundingMode = RoundingMode::HalfAwayFromZero', 'return: self']],
        [Currency::class, 'custom', ['code: string', 'exponent: int', 'name: ?string = NULL', 'symbol: ?string = NULL', 'return: self']],
        [MinorUnits::class, 'rescale', ['minor: string|int', 'fromScale: int', 'toScale: int', 'rounding: RoundingMode = RoundingMode::HalfAwayFromZero', 'return: string']],
        [MinorUnits::class, 'toDecimal', ['minor: string|int', 'scale: int', 'trimTrailingZeros: bool = false', 'return: string']],
        [MinorUnits::class, 'toInt', ['minor: string|int', 'return: int']],
        [RoundingModes::class, 'fromValue', ['value: mixed', 'key: string', 'return: RoundingMode']],
        [Discount::class, 'fixed', ['amount: RoundlyConsulting\Money\Money', 'return: self']],
        [Discount::class, 'percentage', ['percent: RoundlyConsulting\Money\Percentage|string|int', 'return: self']],
        [Discount::class, 'freeShipping', ['return: self']],
        [Discount::class, 'cappedAt', ['cap: RoundlyConsulting\Money\Money', 'return: self']],
        [Discount::class, 'amountFor', ['base: RoundlyConsulting\Money\Money', 'rounding: RoundingMode = RoundingMode::HalfAwayFromZero', 'return: RoundlyConsulting\Money\Money']],
        [DiscountAllocator::class, 'allocate', ['discount: RoundlyConsulting\Money\Money', '...lineTotals: RoundlyConsulting\Money\Money', 'return: array']],
        [Percentage::class, 'fromBasisPoints', ['basisPoints: int', 'return: self']],
        [TaxRate::class, 'fromBasisPoints', ['basisPoints: int', 'label: ?string = NULL', 'return: self']],
        [AsMoney::class, 'currencyColumn', ['column: string', 'return: string']],
        [AsMoney::class, 'fixedCurrency', ['currency: RoundlyConsulting\Money\Currency|string', 'return: string']],
        [MoneyResource::class, 'from', ['money: ?RoundlyConsulting\Money\Money', 'return: ?self']],
    ];

    foreach ($snapshot as [$class, $method, $expected]) {
        expect(signature($class, $method))->toBe($expected, "{$class}::{$method}");
    }

    foreach (['code' => 'string', 'exponent' => 'int', 'iso' => 'bool'] as $property => $type) {
        $reflection = new ReflectionProperty(Currency::class, $property);

        expect($reflection->isPublic() && $reflection->isReadOnly())->toBeTrue()
            ->and(typeString($reflection->getType()))->toBe($type);
    }

    expect(Money::MAX_DIGITS)->toBe(65)
        ->and(class_implements(AsCurrency::class))->toHaveKey(CastsAttributes::class)
        ->and(array_keys(Money::ofMinor(1, 'EUR')->toArray()))->toBe(['minor', 'decimal', 'currency'])
        ->and(Money::ofMinor('92233720368547758070', 'EUR')->toArray()['minor'])->toBeString();

    $table = new Blueprint(Schema::getConnection(), 'snapshot');
    $amount = $table->money(column: 'price', currency: 'currency', nullable: true);
    $table->moneyJson(column: 'snapshot', nullable: false);
    $table->currencyCode(column: 'code', nullable: true);

    expect($amount->get('type'))->toBe('decimal')
        ->and($amount->get('places'))->toBe(0);
});

it('rule 6 — keeps the tier-0 closure (see ArchTest)', function (): void {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(array_values(array_filter(array_keys($composer['require']), static fn (string $name): bool => str_starts_with($name, 'roundly-consulting/'))))
        ->toEqualCanonicalizing(['roundly-consulting/enums-for-laravel', 'roundly-consulting/package-toolkit-for-laravel']);
});

it('rule 7 — returns no int amount except the explicit bridges', function (): void {
    $allowed = [
        'Money::minorInt', 'Money::digits', 'Money::compareTo', 'MinorUnits::toInt',
        'Discount::priority', 'Percentage::basisPoints', 'Percentage::compareTo', 'Ratio::compareTo',
        'EcbExchangeRateProvider::skippedUnknownCurrencies',
        'RateStore::prune', // a row count, not an amount
    ];

    $classes = array_values(array_filter(packageClasses(), static fn (string $class): bool => in_array($class, [Money::class, 'RoundlyConsulting\Money\MoneyBag', MinorUnits::class, Percentage::class, 'RoundlyConsulting\Money\Ratio'], true)
        || str_starts_with($class, 'RoundlyConsulting\Money\Discounts\\')
        || str_starts_with($class, 'RoundlyConsulting\Money\Tax\\')
        || str_starts_with($class, 'RoundlyConsulting\Money\Exchange\\')));

    $found = [];

    foreach ($classes as $class) {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === $class && typeString($method->getReturnType()) === 'int') {
                $found[] = class_basename($class).'::'.$method->getName();
            }
        }
    }

    sort($found);
    sort($allowed);

    expect(count($classes))->toBeGreaterThanOrEqual(20)
        ->and($found)->toBe($allowed);
});

it('rule 8 — casts to int only inside IntegerString', function (): void {
    $scoped = ['Math/', 'Money.php', 'MoneyBag.php', 'Casts/', 'Discounts/', 'Tax/', 'Exchange/'];
    $scanned = 0;
    $offenders = [];

    foreach (sources() as $path => $source) {
        if ($path === 'Math/IntegerString.php' || array_filter($scoped, static fn (string $prefix): bool => str_starts_with($path, $prefix)) === []) {
            continue;
        }

        $scanned++;
        $tokens = codeTokens($source);

        foreach ($tokens as $index => $token) {
            $next = $tokens[$index + 1] ?? null;

            if (is_array($token) && $token[0] === T_INT_CAST && ! (is_array($next) && $next[1] === 'config')) {
                $offenders[] = $path.': (int)';
            }

            if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'intval') {
                $offenders[] = $path.': intval(';
            }
        }
    }

    expect($scanned)->toBeGreaterThanOrEqual(25)
        ->and($offenders)->toBe([]);
});
