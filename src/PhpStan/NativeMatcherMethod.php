<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Expect\Expectation;
use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;

/**
 * Exposes one native matcher on a temporal expectation.
 *
 * @internal
 */
final readonly class NativeMatcherMethod implements MethodReflection
{
    public function __construct(
        private ClassReflection $declaringClass,
        private MethodReflection $matcher,
    ) {}

    #[\Override]
    public function getDeclaringClass(): ClassReflection
    {
        return $this->declaringClass;
    }

    #[\Override]
    public function isStatic(): bool
    {
        return false;
    }

    #[\Override]
    public function isPrivate(): bool
    {
        return false;
    }

    #[\Override]
    public function isPublic(): bool
    {
        return true;
    }

    #[\Override]
    public function getDocComment(): ?string
    {
        return $this->matcher->getDocComment();
    }

    #[\Override]
    public function getName(): string
    {
        return $this->matcher->getName();
    }

    #[\Override]
    public function getPrototype(): ClassMemberReflection
    {
        return $this;
    }

    #[\Override]
    public function getVariants(): array
    {
        $subject = $this->declaringClass->getActiveTemplateTypeMap()->getType('T')
            ?? new MixedType();

        return \array_map(
            static fn($variant): FunctionVariant => new FunctionVariant(
                $variant->getTemplateTypeMap(),
                $variant->getResolvedTemplateTypeMap(),
                $variant->getParameters(),
                $variant->isVariadic(),
                new GenericObjectType(Expectation::class, [$subject]),
            ),
            $this->matcher->getVariants(),
        );
    }

    #[\Override]
    public function isDeprecated(): TrinaryLogic
    {
        return $this->matcher->isDeprecated();
    }

    #[\Override]
    public function getDeprecatedDescription(): ?string
    {
        return $this->matcher->getDeprecatedDescription();
    }

    #[\Override]
    public function isFinal(): TrinaryLogic
    {
        return $this->matcher->isFinal();
    }

    #[\Override]
    public function isInternal(): TrinaryLogic
    {
        return $this->matcher->isInternal();
    }

    #[\Override]
    public function getThrowType(): ?Type
    {
        return $this->matcher->getThrowType();
    }

    #[\Override]
    public function hasSideEffects(): TrinaryLogic
    {
        return $this->matcher->hasSideEffects();
    }
}
