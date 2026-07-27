<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\TypeRenderer;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Tests\Fixture\Doubles\TypeRenderingChild;
use Greenlight\Tests\Fixture\Doubles\TypeRenderingLeft;
use Greenlight\Tests\Fixture\Doubles\TypeRenderingParent;
use Greenlight\Tests\Fixture\Doubles\TypeRenderingRight;

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

        if (!$type instanceof \ReflectionType) {
            Fail::because(\sprintf('Expected %s() to have the requested reflected type.', $method));
        }

        Expect::that(TypeRenderer::render($type, $reflection->getDeclaringClass()))
            ->because('the reflected type renders as valid PHP source')
            ->toBe($expected);
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
