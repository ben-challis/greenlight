<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

use Greenlight\Expect\Equality;
use Greenlight\Expect\ValueRenderer;

/** @internal */
final readonly class EqualsMatcher implements ArgumentMatcher
{
    public function __construct(private mixed $value) {}

    public function matches(mixed $value): bool
    {
        return Equality::equals($this->value, $value);
    }

    public function describe(): string
    {
        return \sprintf('equals(%s)', new ValueRenderer()->render($this->value));
    }
}
