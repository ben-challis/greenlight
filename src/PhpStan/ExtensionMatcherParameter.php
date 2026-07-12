<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\PassedByReference;
use PHPStan\Type\Type;

/**
 * One caller-facing parameter of an extension matcher, backed by the native
 * reflection of the matcher closure.
 *
 * @internal
 */
final readonly class ExtensionMatcherParameter implements ParameterReflection
{
    public function __construct(private \ReflectionParameter $parameter) {}

    #[\Override]
    public function getName(): string
    {
        return $this->parameter->getName();
    }

    #[\Override]
    public function isOptional(): bool
    {
        return $this->parameter->isOptional();
    }

    #[\Override]
    public function getType(): Type
    {
        return NativeType::fromReflection($this->parameter->getType());
    }

    #[\Override]
    public function passedByReference(): PassedByReference
    {
        return PassedByReference::createNo();
    }

    #[\Override]
    public function isVariadic(): bool
    {
        return $this->parameter->isVariadic();
    }

    #[\Override]
    public function getDefaultValue(): ?Type
    {
        return null;
    }
}
