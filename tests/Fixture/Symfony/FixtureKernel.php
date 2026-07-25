<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Symfony;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ReferenceConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Registers private type-based services and an id-only NamedGreeter.
 * Cache and log directories include GREENLIGHT_CHANNEL to prevent parallel
 * workers from compiling the same container.
 */
final class FixtureKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @return list<FrameworkBundle>
     */
    #[\Override]
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle()];
    }

    #[\Override]
    public function getCacheDir(): string
    {
        return $this->stateDir() . '/cache/' . $this->environment;
    }

    #[\Override]
    public function getLogDir(): string
    {
        return $this->stateDir() . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'greenlight-fixture',
            'test' => $this->environment === 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(Greeter::class);
        $services->set(VisitCounter::class);
        $services->set('fixture.named_greeter', NamedGreeter::class);
        // Unreferenced private services are removed even with framework.test;
        // the public hub references them the way a real app would.
        $services->set(FixtureHub::class)
            ->arg('$named', new ReferenceConfigurator('fixture.named_greeter'))
            ->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void {}

    private function stateDir(): string
    {
        $channel = \getenv('GREENLIGHT_CHANNEL');

        return \sys_get_temp_dir() . '/greenlight-symfony-fixture/'
            . (\is_string($channel) && $channel !== '' ? $channel : '0');
    }
}
