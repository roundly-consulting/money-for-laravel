<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\PassedByReference;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\TrinaryLogic;
use PHPStan\Type\VerbosityLevel;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\PHPStan\MoneyMacrosExtension;
use RoundlyConsulting\Money\Support\MoneyBlueprint;
use RoundlyConsulting\Money\Support\MoneyMacros;

/** @param class-string $class */
function reflectionOf(string $class): ClassReflection
{
    return PHPStanTestCase::createReflectionProvider()->getClass($class);
}

/**
 * @return list<string> "name: type = default"
 */
function describeSignature(ParametersAcceptor $variant): array
{
    return array_map(static function ($parameter): string {
        $default = $parameter->getDefaultValue()?->describe(VerbosityLevel::precise());

        return $parameter->getName().': '.$parameter->getType()->describe(VerbosityLevel::precise()).($default === null ? '' : ' = '.$default);
    }, $variant->getParameters());
}

it('declares every registered macro on its classes and subclasses', function (): void {
    $extension = new MoneyMacrosExtension;

    foreach (MoneyBlueprint::MACROS as $macro) {
        expect($extension->hasMethod(reflectionOf(Blueprint::class), $macro))->toBeTrue();
    }

    foreach (MoneyMacros::COLLECTION_MACROS as $macro) {
        foreach ([Collection::class, LazyCollection::class, EloquentCollection::class] as $class) {
            expect($extension->hasMethod(reflectionOf($class), $macro))->toBeTrue();
        }
    }

    expect($extension->hasMethod(reflectionOf(Request::class), 'money'))->toBeTrue()
        ->and($extension->hasMethod(reflectionOf(FormRequest::class), 'money'))->toBeTrue()
        ->and($extension->hasMethod(reflectionOf(Collection::class), 'money'))->toBeFalse()
        ->and($extension->hasMethod(reflectionOf(Request::class), 'sumMoney'))->toBeFalse()
        ->and($extension->hasMethod(reflectionOf(Money::class), 'money'))->toBeFalse();
});

it('describes the frozen blueprint signatures', function (): void {
    $extension = new MoneyMacrosExtension;
    $blueprint = reflectionOf(Blueprint::class);

    $money = $extension->getMethod($blueprint, 'money')->getVariants()[0];

    expect(describeSignature($money))->toBe([
        'column: string',
        'currency: string|false|null = null',
        'nullable: bool = false',
    ])->and($money->getReturnType()->describe(VerbosityLevel::precise()))->toBe('Illuminate\Database\Schema\ColumnDefinition')
        ->and(describeSignature($extension->getMethod($blueprint, 'moneyJson')->getVariants()[0]))->toBe(['column: string', 'nullable: bool = false'])
        ->and(describeSignature($extension->getMethod($blueprint, 'currencyCode')->getVariants()[0]))->toBe(["column: string = 'currency'", 'nullable: bool = false']);
});

it('describes the collection and request signatures', function (): void {
    $extension = new MoneyMacrosExtension;

    $sum = $extension->getMethod(reflectionOf(Collection::class), 'sumMoney')->getVariants()[0];
    $avg = $extension->getMethod(reflectionOf(LazyCollection::class), 'avgMoney')->getVariants()[0];
    $min = $extension->getMethod(reflectionOf(Collection::class), 'minMoney')->getVariants()[0];
    $request = $extension->getMethod(reflectionOf(Request::class), 'money')->getVariants()[0];

    expect(describeSignature($sum))->toBe(['value: (callable(): mixed)|string|null = null', 'currencyIfEmpty: RoundlyConsulting\Money\Currency|string|null = null'])
        ->and($sum->getReturnType()->describe(VerbosityLevel::precise()))->toBe(Money::class)
        ->and(describeSignature($avg))->toBe(['value: (callable(): mixed)|string|null = null', 'rounding: RoundingMode|null = null'])
        ->and(describeSignature($min))->toBe(['value: (callable(): mixed)|string|null = null'])
        ->and(describeSignature($request))->toBe(['key: string', 'currency: RoundlyConsulting\Money\Currency|string|null = null', 'currencyKey: string|null = null'])
        ->and($request->getReturnType()->describe(VerbosityLevel::precise()))->toBe('RoundlyConsulting\Money\Money|null');
});

it('describes a public, non-static, side-effecting method', function (): void {
    $method = (new MoneyMacrosExtension)->getMethod(reflectionOf(Blueprint::class), 'money');

    expect($method->getName())->toBe('money')
        ->and($method->getDeclaringClass()->getName())->toBe(Blueprint::class)
        ->and($method->getPrototype())->toBe($method)
        ->and($method->isPublic())->toBeTrue()
        ->and($method->isPrivate())->toBeFalse()
        ->and($method->isStatic())->toBeFalse()
        ->and($method->getDocComment())->toBeNull()
        ->and($method->getThrowType())->toBeNull()
        ->and($method->getDeprecatedDescription())->toBeNull()
        ->and($method->isDeprecated())->toEqual(TrinaryLogic::createNo())
        ->and($method->isFinal())->toEqual(TrinaryLogic::createNo())
        ->and($method->isInternal())->toEqual(TrinaryLogic::createNo())
        ->and($method->hasSideEffects())->toEqual(TrinaryLogic::createYes());

    foreach ($method->getVariants()[0]->getParameters() as $parameter) {
        expect($parameter->isVariadic())->toBeFalse()
            ->and($parameter->passedByReference()->equals(PassedByReference::createNo()))->toBeTrue();
    }

    expect($method->getVariants()[0]->getParameters()[0]->isOptional())->toBeFalse()
        ->and($method->getVariants()[0]->getParameters()[1]->isOptional())->toBeTrue();
});

it('is wired into composer and extension.neon for consumers', function (): void {
    $root = dirname(__DIR__, 3);
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['extra']['phpstan']['includes'] ?? [])->toContain('extension.neon')
        ->and((string) file_get_contents($root.'/extension.neon'))
        ->toContain(MoneyMacrosExtension::class)
        ->toContain('phpstan.broker.methodsClassReflectionExtension');
});
