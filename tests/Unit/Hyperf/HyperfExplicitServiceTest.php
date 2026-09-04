<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Hyperf;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Service;
use Greenlight\Hyperf\HyperfBridgeError;
use Greenlight\Hyperf\HyperfPlugin;
use Greenlight\Tests\Support\Psr11\ArrayContainer;
use Greenlight\Tests\Support\ServiceResolverProbe;

final readonly class HyperfExplicitServiceTest
{
    #[Test]
    public function anExplicitTypeIdStopsTheResolverChainWhenTheServiceIsMissing(): void
    {
        $plugin = new HyperfPlugin(__DIR__);
        new \ReflectionProperty(HyperfPlugin::class, 'activeContainer')->setValue($plugin, new ArrayContainer([]));
        $later = new ServiceResolverProbe(new \ArrayObject());
        $scopes = new HarnessScopes([], [$plugin, $later]);

        Expect::that(static fn(): object => $scopes->resolve(
            \ArrayObject::class,
            'test',
            [new Service(\ArrayObject::class)],
        ))->toThrow(HyperfBridgeError::class);
        Expect::that($later->calls)->toBe(0);
    }
}
