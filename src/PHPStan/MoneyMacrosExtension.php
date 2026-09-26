<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\PHPStan;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Type\BooleanType;
use PHPStan\Type\CallableType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\TypeCombinator;
use RoundingMode;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Money;

/**
 * Makes money's macros visible to PHPStan: the Blueprint schema macros, the collection
 * aggregates and `Request::money()`. A reflection extension rather than a stub because the
 * toolkit already stubs Blueprint and PHPStan honours one stub per class. Loaded into
 * consumers through `extra.phpstan.includes` (phpstan/extension-installer).
 */
final class MoneyMacrosExtension implements MethodsClassReflectionExtension
{
    private const array BLUEPRINT = ['money', 'moneyJson', 'currencyCode'];

    private const array COLLECTION = ['sumMoney', 'minMoney', 'maxMoney', 'avgMoney'];

    private const array REQUEST = ['money'];

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return (in_array($methodName, self::BLUEPRINT, true) && self::is($classReflection, [Blueprint::class]))
            || (in_array($methodName, self::COLLECTION, true) && self::is($classReflection, [Collection::class, LazyCollection::class]))
            || (in_array($methodName, self::REQUEST, true) && self::is($classReflection, [Request::class]));
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        if (self::is($classReflection, [Blueprint::class])) {
            return new MacroMethodReflection($classReflection, $methodName, $this->blueprintParameters($methodName), new ObjectType(ColumnDefinition::class));
        }

        if (self::is($classReflection, [Request::class])) {
            return new MacroMethodReflection($classReflection, $methodName, $this->requestParameters(), TypeCombinator::addNull(new ObjectType(Money::class)));
        }

        return new MacroMethodReflection($classReflection, $methodName, $this->collectionParameters($methodName), new ObjectType(Money::class));
    }

    /**
     * @return list<MacroParameterReflection>
     */
    private function blueprintParameters(string $method): array
    {
        $nullable = new MacroParameterReflection('nullable', new BooleanType, new ConstantBooleanType(false));

        return match ($method) {
            'money' => [
                new MacroParameterReflection('column', new StringType),
                new MacroParameterReflection('currency', TypeCombinator::union(new StringType, new ConstantBooleanType(false), new NullType), new NullType),
                $nullable,
            ],
            'moneyJson' => [new MacroParameterReflection('column', new StringType), $nullable],
            default => [new MacroParameterReflection('column', new StringType, new ConstantStringType('currency')), $nullable],
        };
    }

    /**
     * @return list<MacroParameterReflection>
     */
    private function collectionParameters(string $method): array
    {
        $value = new MacroParameterReflection('value', TypeCombinator::union(new CallableType, new StringType, new NullType), new NullType);

        return match ($method) {
            'sumMoney' => [$value, new MacroParameterReflection('currencyIfEmpty', TypeCombinator::union(new ObjectType(Currency::class), new StringType, new NullType), new NullType)],
            'avgMoney' => [$value, new MacroParameterReflection('rounding', TypeCombinator::addNull(new ObjectType(RoundingMode::class)), new NullType)],
            default => [$value],
        };
    }

    /**
     * @return list<MacroParameterReflection>
     */
    private function requestParameters(): array
    {
        return [
            new MacroParameterReflection('key', new StringType),
            new MacroParameterReflection('currency', TypeCombinator::union(new ObjectType(Currency::class), new StringType, new NullType), new NullType),
            new MacroParameterReflection('currencyKey', TypeCombinator::addNull(new StringType), new NullType),
        ];
    }

    /**
     * @param  list<class-string>  $classes
     */
    private static function is(ClassReflection $classReflection, array $classes): bool
    {
        foreach ($classes as $class) {
            if ($classReflection->is($class)) {
                return true;
            }
        }

        return false;
    }
}
