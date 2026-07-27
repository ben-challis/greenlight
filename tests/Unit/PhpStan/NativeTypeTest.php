<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\NativeType;
use PHPStan\Type\VerbosityLevel;

final class NativeTypeTest
{
    #[Test]
    #[DataSet('nativeTypes')]
    public function reflectedNativeTypesMapToPhpStanTypes(string $method, string $expected): void
    {
        $type = new \ReflectionMethod(self::class, $method)->getParameters()[0]->getType();

        Expect::that(NativeType::fromReflection($type)->describe(VerbosityLevel::typeOnly()))
            ->because('reflected native types map to their PHPStan equivalents')
            ->toBe($expected);
    }

    /** @param non-empty-list<class-string> $expected */
    #[Test]
    #[DataSet('objectTypes')]
    public function reflectedObjectTypesKeepTheirRequiredClasses(string $method, array $expected): void
    {
        $type = new \ReflectionMethod(self::class, $method)->getParameters()[0]->getType();
        $mapped = NativeType::fromReflection($type);

        Expect::that($mapped->isObject()->yes())
            ->because('reflected object types stay object types')
            ->toBeTrue()
            ->and($mapped->getObjectClassNames())
            ->because('reflected object types keep every required class')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function nativeTypes(): iterable
    {
        yield 'untyped' => ['untyped', 'mixed'];
        yield 'nullable' => ['nullable', 'int|null'];
        yield 'union' => ['union', 'int|string'];
        yield 'array' => ['array', 'array'];
        yield 'iterable' => ['iterable', 'iterable'];
        yield 'callable' => ['callable', 'callable'];
        yield 'object' => ['object', 'object'];
        yield 'null' => ['null', 'null'];
        yield 'mixed' => ['mixed', 'mixed'];
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-list<class-string>}>
     */
    public static function objectTypes(): iterable
    {
        yield 'intersection' => ['intersection', [\JsonSerializable::class, \Stringable::class]];
        yield 'class' => ['class', [\DateTimeInterface::class]];
    }

    /** @param mixed $value */
    public function untyped($value): mixed
    {
        return $value;
    }

    public function nullable(?int $value): ?int
    {
        return $value;
    }

    public function union(int|string $value): int|string
    {
        return $value;
    }

    public function intersection(\JsonSerializable&\Stringable $value): \JsonSerializable&\Stringable
    {
        return $value;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    public function array(array $value): array
    {
        return $value;
    }

    /**
     * @param iterable<mixed> $value
     *
     * @return iterable<mixed>
     */
    public function iterable(iterable $value): iterable
    {
        return $value;
    }

    public function callable(callable $value): callable
    {
        return $value;
    }

    public function object(object $value): object
    {
        return $value;
    }

    public function null(null $value): null
    {
        return $value;
    }

    public function mixed(mixed $value): mixed
    {
        return $value;
    }

    public function class(\DateTimeInterface $value): \DateTimeInterface
    {
        return $value;
    }
}
