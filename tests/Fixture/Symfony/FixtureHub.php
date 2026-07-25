<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Symfony;

/**
 * References private fixture services so Symfony's compiler keeps them.
 */
final readonly class FixtureHub
{
    public function __construct(
        public Greeter $greeter,
        public VisitCounter $counter,
        public NamedGreeter $named,
    ) {}
}
