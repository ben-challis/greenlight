<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\AllOf;
use Greenlight\Doubles\Argument;
use Greenlight\Doubles\ArgumentType;
use Greenlight\Doubles\IntersectionTypeMatcher;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Doubles\UnionTypeMatcher;
use Greenlight\Expect\Expect;

final class ArgumentTypeTest
{
    #[Test]
    #[DataSet('nativeTypes')]
    public function reflectedTypesAcceptTheirNativeValues(
        string $method,
        mixed $accepted,
        mixed $rejected,
        bool $mustReject,
    ): void {
        $type = $this->parameterType($method);

        Expect::that($type->accepts($accepted))
            ->because($method . ' MUST accept its compatible value')
            ->toBeTrue();

        if ($mustReject) {
            Expect::that($type->accepts($rejected))
                ->because($method . ' MUST reject its incompatible value')
                ->toBeFalse();
        }
    }

    /** @return iterable<string, array{string, mixed, mixed, bool}> */
    public static function nativeTypes(): iterable
    {
        yield 'mixed' => ['mixedType', 1, null, false];
        yield 'null' => ['nullType', null, false, true];
        yield 'true' => ['trueType', true, false, true];
        yield 'false' => ['falseType', false, true, true];
        yield 'bool' => ['boolType', true, 1, true];
        yield 'int' => ['intType', 1, 1.0, true];
        yield 'float' => ['floatType', 1.0, 1, true];
        yield 'string' => ['stringType', 'value', 1, true];
        yield 'array' => ['arrayType', [], new \stdClass(), true];
        yield 'object' => ['objectType', new \stdClass(), [], true];
        yield 'callable' => ['callableType', static fn(): null => null, 1, true];
        yield 'iterable' => ['iterableType', [], 1, true];
        yield 'class' => ['classType', new \DateTimeImmutable(), new \stdClass(), true];
        yield 'enum' => ['enumType', ArgumentTypeEnum::Value, 'value', true];
    }

    #[Test]
    public function reflectedTypesPreserveNullableUnionIntersectionAndRelativeTypes(): void
    {
        $nullable = $this->parameterType('nullableType');
        $combined = $this->parameterType('combinedType');
        $self = $this->parameterType('selfType');
        $parent = $this->parameterType('parentType', ArgumentTypeChild::class);
        $static = $this->returnType('staticType', ArgumentTypeChild::class);

        Expect::that($nullable->accepts(null))->toBeTrue();
        Expect::that($nullable->describe())->toBe('string|null');
        Expect::that($combined->accepts(new \ArrayObject()))->toBeTrue();
        Expect::that($combined->accepts('value'))->toBeTrue();
        Expect::that($combined->accepts(null))->toBeTrue();
        Expect::that($combined->accepts(new \stdClass()))->toBeFalse();
        Expect::that($combined->describe())->toBe('(IteratorAggregate&Countable)|string|null');
        Expect::that($self->accepts(new ArgumentTypeFixture()))->toBeTrue();
        Expect::that($self->describe())->toBe(ArgumentTypeFixture::class);
        Expect::that($parent->accepts(new ArgumentTypeParent()))->toBeTrue();
        Expect::that($parent->describe())->toBe(ArgumentTypeParent::class);
        Expect::that($static->describe())->toBe(ArgumentTypeChild::class);
    }

    #[Test]
    public function namedTypeFactoriesKeepOnlySoundKnownBounds(): void
    {
        Expect::that(ArgumentType::fromTypeName('\\' . \DateTimeInterface::class)?->describe())
            ->toBe(\DateTimeInterface::class);
        Expect::that(ArgumentType::fromTypeName(ArgumentTypeEnum::class)?->describe())
            ->toBe(ArgumentTypeEnum::class);
        Expect::that(ArgumentType::fromTypeName('unknown type'))->toBeNull();
        Expect::that(ArgumentType::fromIntersectionTypeNames(['unknown type']))->toBeNull();
        Expect::that(ArgumentType::fromIntersectionTypeNames(['int', 'unknown type'])?->describe())
            ->toBe('int');
        Expect::that(ArgumentType::fromUnionTypeNames(['int', 'unknown type']))->toBeNull();
        Expect::that(ArgumentType::fromUnionTypeNames(['int', 'string'])?->describe())
            ->toBe('int|string');
    }

    #[Test]
    #[DataSet('typeOverlaps')]
    public function overlapDetectsPossibleSharedValues(string $left, string $right, bool $expected): void
    {
        Expect::that($this->parameterType($left)->overlaps($this->parameterType($right)))
            ->because($left . ' and ' . $right . ' MUST have the specified overlap')
            ->toBe($expected);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function typeOverlaps(): iterable
    {
        yield 'same type' => ['intType', 'intType', true];
        yield 'mixed and scalar' => ['mixedType', 'intType', true];
        yield 'bool and true' => ['boolType', 'trueType', true];
        yield 'class hierarchy' => ['childClassType', 'parentClassType', true];
        yield 'implemented interface' => ['implementerType', 'firstInterfaceType', true];
        yield 'two interfaces' => ['firstInterfaceType', 'secondInterfaceType', true];
        yield 'interface and open class' => ['firstInterfaceType', 'openClassType', true];
        yield 'open class and interface' => ['openClassType', 'firstInterfaceType', true];
        yield 'interface and closed class' => ['firstInterfaceType', 'closedClassType', false];
        yield 'closed class and interface' => ['closedClassType', 'firstInterfaceType', false];
        yield 'unrelated classes' => ['openClassType', 'parentClassType', false];
        yield 'object and class' => ['objectType', 'closedClassType', true];
        yield 'class and object' => ['closedClassType', 'objectType', true];
        yield 'callable and invokable class' => ['callableType', 'invokableType', true];
        yield 'invokable class and callable' => ['invokableType', 'callableType', true];
        yield 'callable and open class' => ['callableType', 'openClassType', true];
        yield 'callable and closed class' => ['callableType', 'closedClassType', false];
        yield 'iterable and iterable class' => ['iterableType', 'iterableClassType', true];
        yield 'iterable class and iterable' => ['iterableClassType', 'iterableType', true];
        yield 'iterable and open class' => ['iterableType', 'openClassType', true];
        yield 'iterable and closed class' => ['iterableType', 'closedClassType', false];
        yield 'callable and string' => ['callableType', 'stringType', true];
        yield 'iterable and array' => ['iterableType', 'arrayType', true];
        yield 'callable and iterable' => ['callableType', 'iterableType', true];
        yield 'disjoint scalars' => ['intType', 'stringType', false];
    }

    #[Test]
    public function matcherCompositionsExposeTheirKnownAcceptedTypes(): void
    {
        $untyped = new AllOf([Argument::any(), Argument::any()]);
        $intersection = new IntersectionTypeMatcher(['int', 'unknown type']);
        $unknownIntersection = new IntersectionTypeMatcher(['first unknown', 'second unknown']);
        $union = new UnionTypeMatcher(['int', 'string']);
        $unknownUnion = new UnionTypeMatcher(['int', 'unknown type']);

        Expect::that($untyped->argumentType())->toBeNull();
        Expect::that($intersection->argumentType()?->describe())->toBe('int');
        Expect::that($unknownIntersection->argumentType())->toBeNull();
        Expect::that($union->argumentType()?->describe())->toBe('int|string');
        Expect::that($unknownUnion->argumentType())->toBeNull();
    }

    #[Test]
    public function parentTypeRequiresItsDeclaringClassContext(): void
    {
        $reflection = new \ReflectionMethod(ArgumentTypeChild::class, 'parentType');
        $type = $reflection->getParameters()[0]->getType();

        Expect::that($type)->toBeInstanceOf(\ReflectionType::class);
        Expect::that(static fn(): ArgumentType => ArgumentType::fromReflection($type))
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'Closure uses the parent type but has no parent class.',
            );
        Expect::that(static fn(): ArgumentType => ArgumentType::fromReflection(
            $type,
            self::reflectionClass(\stdClass::class),
        ))->toThrow(
            InvalidDoubleUsage::class,
            message: 'stdClass uses the parent type but has no parent class.',
        );
    }

    #[Test]
    public function unsupportedReflectionTypesAreRejected(): void
    {
        Expect::that(static fn(): ArgumentType => ArgumentType::fromReflection(
            new ArgumentTypeUnsupportedReflectionType(),
        ))->toThrow(
            InvalidDoubleUsage::class,
            message: 'Unsupported reflection type ' . ArgumentTypeUnsupportedReflectionType::class . '.',
        );
        Expect::that(static fn(): ArgumentType => ArgumentType::fromReflection(
            new ArgumentTypeEmptyNamedType(),
        ))->toThrow(
            InvalidDoubleUsage::class,
            message: 'Unsupported reflection type ' . ArgumentTypeEmptyNamedType::class . '.',
        );
    }

    #[Test]
    public function unresolvedClassTypesRemainConservative(): void
    {
        $unresolved = ArgumentType::fromReflection(new ArgumentTypeUnresolvedNamedType());

        Expect::that($unresolved->overlaps($this->parameterType('closedClassType')))->toBeTrue();
        Expect::that($this->parameterType('callableType')->overlaps($unresolved))->toBeTrue();
        Expect::that($this->parameterType('iterableType')->overlaps($unresolved))->toBeTrue();
    }

    /**
     * @param class-string $class
     * @throws InvalidDoubleUsage
     */
    private function parameterType(string $method, string $class = ArgumentTypeFixture::class): ArgumentType
    {
        $reflection = new \ReflectionMethod($class, $method);
        $type = $reflection->getParameters()[0]->getType();

        if (!$type instanceof \ReflectionType) {
            throw new \LogicException('The test type parameter must have a type.');
        }

        return ArgumentType::fromReflection($type, $reflection->getDeclaringClass());
    }

    /**
     * @param class-string $class
     * @throws InvalidDoubleUsage
     */
    private function returnType(string $method, string $class): ArgumentType
    {
        $reflection = new \ReflectionMethod($class, $method);
        $type = $reflection->getReturnType();

        if (!$type instanceof \ReflectionType) {
            throw new \LogicException('The test method must have a return type.');
        }

        return ArgumentType::fromReflection($type, $reflection->getDeclaringClass());
    }

    /**
     * @param class-string<object> $class
     * @return \ReflectionClass<object>
     */
    private static function reflectionClass(string $class): \ReflectionClass
    {
        return new \ReflectionClass($class);
    }
}

final class ArgumentTypeFixture
{
    public static function mixedType(mixed $value): void
    {
        unset($value);
    }

    public static function nullType(null $value): void
    {
        unset($value);
    }

    public static function trueType(true $value): void
    {
        unset($value);
    }

    public static function falseType(false $value): void
    {
        unset($value);
    }

    public static function boolType(bool $value): void
    {
        unset($value);
    }

    public static function intType(int $value): void
    {
        unset($value);
    }

    public static function floatType(float $value): void
    {
        unset($value);
    }

    public static function stringType(string $value): void
    {
        unset($value);
    }

    /** @param array<array-key, mixed> $value */
    public static function arrayType(array $value): void
    {
        unset($value);
    }

    public static function objectType(object $value): void
    {
        unset($value);
    }

    /** @param callable(): mixed $value */
    public static function callableType(callable $value): void
    {
        unset($value);
    }

    /** @param iterable<mixed, mixed> $value */
    public static function iterableType(iterable $value): void
    {
        unset($value);
    }

    public static function classType(\DateTimeInterface $value): void
    {
        unset($value);
    }

    public static function enumType(ArgumentTypeEnum $value): void
    {
        unset($value);
    }

    public static function nullableType(?string $value): void
    {
        unset($value);
    }

    /** @param (\IteratorAggregate<mixed, mixed>&\Countable)|string|null $value */
    public static function combinedType((\IteratorAggregate&\Countable)|string|null $value): void
    {
        unset($value);
    }

    public static function selfType(self $value): void
    {
        unset($value);
    }

    public static function firstInterfaceType(ArgumentTypeFirstInterface $value): void
    {
        unset($value);
    }

    public static function secondInterfaceType(ArgumentTypeSecondInterface $value): void
    {
        unset($value);
    }

    public static function openClassType(ArgumentTypeOpenClass $value): void
    {
        unset($value);
    }

    public static function closedClassType(ArgumentTypeClosedClass $value): void
    {
        unset($value);
    }

    public static function parentClassType(ArgumentTypeParent $value): void
    {
        unset($value);
    }

    public static function childClassType(ArgumentTypeChild $value): void
    {
        unset($value);
    }

    public static function implementerType(ArgumentTypeImplementer $value): void
    {
        unset($value);
    }

    public static function invokableType(ArgumentTypeInvokable $value): void
    {
        unset($value);
    }

    public static function iterableClassType(ArgumentTypeIterable $value): void
    {
        unset($value);
    }
}

interface ArgumentTypeFirstInterface {}

interface ArgumentTypeSecondInterface {}

class ArgumentTypeOpenClass {}

final class ArgumentTypeClosedClass {}

class ArgumentTypeParent {}

final class ArgumentTypeChild extends ArgumentTypeParent
{
    public static function parentType(parent $value): void {}

    public static function staticType(): static
    {
        return new self();
    }
}

final class ArgumentTypeImplementer implements ArgumentTypeFirstInterface {}

final class ArgumentTypeInvokable
{
    public function __invoke(): void {}
}

/** @implements \IteratorAggregate<never, never> */
final class ArgumentTypeIterable implements \IteratorAggregate
{
    /** @return \Traversable<never, never> */
    public function getIterator(): \Traversable
    {
        yield from [];
    }
}

enum ArgumentTypeEnum
{
    case Value;
}

final class ArgumentTypeUnsupportedReflectionType extends \ReflectionType {}

final class ArgumentTypeEmptyNamedType extends \ReflectionNamedType
{
    public function getName(): string
    {
        return '';
    }

    public function isBuiltin(): bool
    {
        return false;
    }

    public function allowsNull(): bool
    {
        return false;
    }

    public function __toString(): string
    {
        return '';
    }
}

final class ArgumentTypeUnresolvedNamedType extends \ReflectionNamedType
{
    public function getName(): string
    {
        return 'UnresolvedClass';
    }

    public function isBuiltin(): bool
    {
        return false;
    }

    public function allowsNull(): bool
    {
        return false;
    }

    public function __toString(): string
    {
        return 'UnresolvedClass';
    }
}
