<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\TypeRenderer;
use Greenlight\Tests\Fixture\Doubles\TypeRenderingChild;
use Greenlight\Tests\Fixture\Doubles\TypeRenderingLeft;
use Greenlight\Tests\Fixture\Doubles\TypeRenderingParent;
use Greenlight\Tests\Fixture\Doubles\TypeRenderingRight;

use function Greenlight\expect;

final class TypeRendererTest
{
    #[Test]
    #[DataSet('supportedTypes')]
    public function supportedReflectedTypesRenderAsPhpSource(string $method, ?int $parameter, string $expected): void
    {
        $reflection = new \ReflectionMethod(TypeRenderingChild::class, $method);
        $type = $parameter === null
            ? $reflection->getReturnType()
            : $reflection->getParameters()[$parameter]->getType();

        expect($type)
            ->because(\sprintf('%s() MUST have the requested reflected type.', $method))
            ->toBeInstanceOf(\ReflectionType::class);

        expect(TypeRenderer::render($type, $reflection->getDeclaringClass()))
            ->because('the reflected type renders as valid PHP source')
            ->toBe($expected);
    }

    #[Test]
    public function nullableClassAndNullAcceptingNamedTypesRemainValidPhp(): void
    {
        $nullableClassAndMixed = new \ReflectionFunction(
            static fn(?\ArrayObject $value): mixed => $value,
        );
        $returnsNull = new \ReflectionFunction(static fn(): null => null);
        $nullableClass = $nullableClassAndMixed->getParameters()[0]->getType();
        $mixed = $nullableClassAndMixed->getReturnType();
        $null = $returnsNull->getReturnType();

        expect($nullableClass)
            ->because('The nullable-class closure MUST expose its declared type.')
            ->toBeInstanceOf(\ReflectionType::class);
        expect($mixed)
            ->because('The mixed closure MUST expose its declared type.')
            ->toBeInstanceOf(\ReflectionType::class);
        expect($null)
            ->because('The null closure MUST expose its declared type.')
            ->toBeInstanceOf(\ReflectionType::class);

        $context = new \ReflectionMethod(TypeRenderingChild::class, 'nullable')->getDeclaringClass();

        expect(TypeRenderer::render($nullableClass, $context))
            ->because('nullable class names MUST retain valid PHP syntax')
            ->toBe('?\ArrayObject');
        expect(TypeRenderer::render($mixed, $context))
            ->because('mixed MUST NOT gain a nullable prefix')
            ->toBe('mixed');
        expect(TypeRenderer::render($null, $context))
            ->because('null MUST NOT gain a nullable prefix')
            ->toBe('null');
    }

    /**
     * @return iterable<string, array{string, int|null, string}>
     */
    public static function supportedTypes(): iterable
    {
        yield 'nullable built-in' => ['nullable', 0, '?string'];

        yield 'named class' => ['named', 0, '\\' . TypeRenderingParent::class];

        yield 'self' => ['acceptsSelf', 0, '\\' . TypeRenderingChild::class];

        yield 'parent' => ['acceptsParent', 0, '\\' . TypeRenderingParent::class];

        yield 'static' => ['returnsStatic', null, 'static'];

        yield 'intersection' => [
            'intersection',
            0,
            '\\' . TypeRenderingLeft::class . '&\\' . TypeRenderingRight::class,
        ];

        yield 'union with intersection' => [
            'unionWithIntersection',
            0,
            '(\\' . TypeRenderingLeft::class . '&\\' . TypeRenderingRight::class . ')|string|null',
        ];
    }
}
