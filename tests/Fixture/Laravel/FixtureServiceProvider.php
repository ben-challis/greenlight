<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Registers type-based singletons and an id-only NamedGreeter. Singletons
 * make within-application identity and cross-test freshness observable.
 */
final class FixtureServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(Greeter::class);
        $this->app->singleton(VisitCounter::class);
        $this->app->singleton('fixture.named_greeter', static fn(): NamedGreeter => new NamedGreeter());
    }
}
