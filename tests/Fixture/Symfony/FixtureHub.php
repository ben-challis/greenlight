<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Symfony;

/**
 * Public service referencing the private fixtures so the compiler keeps
 * them.
 *
 * Symfony removes unreferenced private services even in the test
 * environment; real apps reference their services somewhere, and this hub
 * plays that part for the fixture container.
 */
final readonly class FixtureHub
{
    public function __construct(
        public Greeter $greeter,
        public VisitCounter $counter,
        public NamedGreeter $named,
    ) {}
}
