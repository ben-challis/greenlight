<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Expect\Expect;

/**
 * Snapshot of the config file API. Update it only for an intentional API
 * change.
 */
final class GreenlightConfigApiTest
{
    #[Test]
    public function publicMethodListIsExactlyTheDocumentedSurface(): void
    {
        $reflection = new \ReflectionClass(GreenlightConfig::class);
        $methods = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $methods[] = $method->getName();
        }

        \sort($methods);

        Expect::that($methods)->toBe([
            'build',
            'coverage',
            'create',
            'failFast',
            'failOnDeprecation',
            'failOnNotice',
            'failOnRisky',
            'ignoreDeprecationsMatching',
            'paths',
            'plugins',
            'randomizeOrder',
            'suite',
            'watch',
            'workers',
        ]);
    }

    #[Test]
    public function builderCannotBeConstructedDirectly(): void
    {
        $constructor = new \ReflectionMethod(GreenlightConfig::class, '__construct');

        Expect::that($constructor->isPrivate())->toBeTrue();
    }
}
