<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Symfony;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Symfony\SymfonyBridgeError;
use Greenlight\Symfony\SymfonyPlugin;
use Greenlight\Tests\Fixture\Symfony\BareKernel;
use Greenlight\Tests\Fixture\Symfony\Greeter;
use Symfony\Component\HttpKernel\KernelInterface;

final class SymfonyPluginInvalidKernelCacheTest
{
    #[Test]
    public function anInvalidKernelIsNotCached(): void
    {
        $factoryCalls = 0;
        $plugin = new SymfonyPlugin(static function () use (&$factoryCalls): KernelInterface {
            ++$factoryCalls;

            return BareKernel::withoutTestContainer();
        });
        $resolve = static fn(): ?object => $plugin->resolve(Greeter::class, []);

        Expect::that($resolve)
            ->because('the first invalid kernel fails validation')
            ->toThrow(SymfonyBridgeError::class, matching: '/without the Symfony test container/');
        Expect::that($resolve)
            ->because('a later resolution validates a new kernel')
            ->toThrow(SymfonyBridgeError::class, matching: '/without the Symfony test container/');
        Expect::that($factoryCalls)
            ->because('an invalid kernel MUST NOT enter the plugin cache')
            ->toBe(2);
    }
}
