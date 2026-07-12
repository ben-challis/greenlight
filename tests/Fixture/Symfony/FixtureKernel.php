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
 * Minimal single-file kernel the Symfony bridge tests boot.
 *
 * configureContainer() enables framework.test only in the test environment,
 * so the same kernel proves both the test-container path and the
 * missing-test-container error. It registers Greeter and VisitCounter as
 * ordinary private autoconfigured services, and NamedGreeter under the
 * string id fixture.named_greeter only, which forces the #[Service]
 * attribute.
 *
 * getCacheDir() and getLogDir() are keyed by GREENLIGHT_CHANNEL so parallel
 * workers never compile the same container directory concurrently.
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
