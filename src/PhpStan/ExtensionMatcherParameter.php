<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\PassedByReference;
use PHPStan\Type\Type;

/** @internal */
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
        return NativeType::fromParameter($this->parameter);
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
