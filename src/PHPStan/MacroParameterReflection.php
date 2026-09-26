<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\PHPStan;

use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\PassedByReference;
use PHPStan\Type\Type;

/**
 * One parameter of a money macro. Hand-rolled rather than PHPStan's DummyParameter, which
 * is outside PHPStan's backward-compatibility promise.
 */
final class MacroParameterReflection implements ParameterReflection
{
    public function __construct(
        private readonly string $name,
        private readonly Type $type,
        private readonly ?Type $defaultValue = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function isOptional(): bool
    {
        return $this->defaultValue !== null;
    }

    public function getType(): Type
    {
        return $this->type;
    }

    public function passedByReference(): PassedByReference
    {
        return PassedByReference::createNo();
    }

    public function isVariadic(): bool
    {
        return false;
    }

    public function getDefaultValue(): ?Type
    {
        return $this->defaultValue;
    }
}
