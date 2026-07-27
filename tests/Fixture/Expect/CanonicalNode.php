<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Expect;

final class CanonicalNode
{
    public ?CanonicalNode $next = null;

    public function __construct(public readonly string $name) {}
}
