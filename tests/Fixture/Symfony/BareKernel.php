<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Symfony;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Hand-rolled kernel whose container carries whatever the test seeded, for
 * exercising the bridge's boot-time capability validation.
 *
 * withTestContainer() returns a kernel exposing a test container but no
 * services_resetter; withoutTestContainer() returns one exposing neither.
 * Compiling a real framework-bundle container without services_resetter is
 * not practical, since the bundle always tags resettable services.
 */
final class BareKernel implements KernelInterface
{
    private function __construct(
        private readonly Container $container,
    ) {}

    public static function withTestContainer(): self
    {
        $container = new Container();
        $container->set('test.service_container', $container);

        return new self($container);
    }

    public static function withoutTestContainer(): self
    {
        return new self(new Container());
    }

    #[\Override]
    public function boot(): void {}

    #[\Override]
    public function shutdown(): void {}

    #[\Override]
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    #[\Override]
    public function getEnvironment(): string
    {
        return 'bare';
    }

    #[\Override]
    public function isDebug(): bool
    {
        return false;
    }

    /**
     * @return iterable<never>
     */
    #[\Override]
    public function registerBundles(): iterable
    {
        return [];
    }

    #[\Override]
    public function registerContainerConfiguration(LoaderInterface $loader): void {}

    /**
     * @return array{}
     */
    #[\Override]
    public function getBundles(): array
    {
        return [];
    }

    #[\Override]
    public function getBundle(string $name): never
    {
        throw new \InvalidArgumentException('BareKernel has no bundles.');
    }

    #[\Override]
    public function locateResource(string $name): string
    {
        throw new \InvalidArgumentException('BareKernel has no resources.');
    }

    #[\Override]
    public function getStartTime(): float
    {
        return 0.0;
    }

    #[\Override]
    public function getProjectDir(): string
    {
        return \sys_get_temp_dir();
    }

    #[\Override]
    public function getCacheDir(): string
    {
        return \sys_get_temp_dir();
    }

    #[\Override]
    public function getBuildDir(): string
    {
        return \sys_get_temp_dir();
    }

    #[\Override]
    public function getShareDir(): ?string
    {
        return null;
    }

    #[\Override]
    public function getLogDir(): ?string
    {
        return null;
    }

    #[\Override]
    public function getCharset(): string
    {
        return 'UTF-8';
    }

    #[\Override]
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        throw new \LogicException('BareKernel does not handle requests.');
    }
}
