<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Expect\Expectation;
use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * An extension matcher presented to PHPStan as a method on Expectation:
 * the matcher closure's parameters minus the subject, returning the chain.
 *
 * @internal
 */
final readonly class ExtensionMatcherMethod implements MethodReflection
{
    /**
     * @param list<\ReflectionParameter> $parameters
     */
    public function __construct(
        private ClassReflection $declaringClass,
        private string $name,
        private array $parameters,
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
        return null;
    }

    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }

    #[\Override]
    public function getPrototype(): ClassMemberReflection
    {
        return $this;
    }

    #[\Override]
    public function getVariants(): array
    {
        $parameters = \array_map(
            static fn(\ReflectionParameter $parameter): ExtensionMatcherParameter => new ExtensionMatcherParameter($parameter),
            $this->parameters,
        );

        $lastParameter = $this->parameters[\array_key_last($this->parameters) ?? -1] ?? null;

        return [
            new FunctionVariant(
                TemplateTypeMap::createEmpty(),
                null,
                $parameters,
                $lastParameter instanceof \ReflectionParameter && $lastParameter->isVariadic(),
                new ObjectType(Expectation::class),
            ),
        ];
    }

    #[\Override]
    public function isDeprecated(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    #[\Override]
    public function getDeprecatedDescription(): ?string
    {
        return null;
    }

    #[\Override]
    public function isFinal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    #[\Override]
    public function isInternal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    #[\Override]
    public function getThrowType(): ?Type
    {
        return null;
    }

    #[\Override]
    public function hasSideEffects(): TrinaryLogic
    {
        return TrinaryLogic::createYes();
    }
}
