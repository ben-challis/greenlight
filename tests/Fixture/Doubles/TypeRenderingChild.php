<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

abstract class TypeRenderingChild extends TypeRenderingParent
{
    abstract public function nullable(?string $value): void;

    abstract public function named(TypeRenderingParent $value): void;

    abstract public function acceptsSelf(self $value): void;

    abstract public function acceptsParent(parent $value): void;

    abstract public function returnsStatic(): static;

    abstract public function intersection(TypeRenderingLeft&TypeRenderingRight $value): void;

    abstract public function unionWithIntersection((TypeRenderingLeft&TypeRenderingRight)|string|null $value): void;
}
