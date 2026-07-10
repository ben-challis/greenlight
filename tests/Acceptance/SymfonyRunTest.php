<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * The Symfony bridge through the real CLI.
 *
 * The generated project boots a single-file kernel via SymfonyPlugin and its
 * tests constructor-inject a container service by type, an id-only service
 * through the #[Service] attribute, the KernelInterface harness service, and
 * a stateful counter whose reset between tests proves the services_resetter
 * hook. Two classes across two workers each compile their own container into
 * a channel-suffixed cache directory.
 */
final readonly class SymfonyRunTest
{
    #[Test]
    public function injectsContainerServicesAndResetsStateBetweenTests(): void
    {
        $project = $this->writeProject();

        try {
            [$exit, $output] = $project->run('run', '--reporter=plain');

            Expect::that($exit)->toBe(0)
                ->and($output)->toContain('4 tests, 4 passed');
        } finally {
            $project->remove();
        }
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create('symfony');

        $project->write('probe.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SymfonyProbe;

            use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
            use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
            use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
            use Symfony\Component\HttpKernel\Kernel;
            use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
            use Symfony\Contracts\Service\ResetInterface;

            final class Greeter
            {
                public function greet(string $name): string
                {
                    return 'Hello, ' . $name . '!';
                }
            }

            final class NamedGreeter
            {
                public function greet(): string
                {
                    return 'Hello from probe.named_greeter!';
                }
            }

            final class VisitCounter implements ResetInterface
            {
                private int $visits = 0;

                public function record(): void
                {
                    ++$this->visits;
                }

                public function count(): int
                {
                    return $this->visits;
                }

                public function reset(): void
                {
                    $this->visits = 0;
                }
            }

            final class ProbeKernel extends Kernel
            {
                use MicroKernelTrait;

                public function registerBundles(): iterable
                {
                    return [new FrameworkBundle()];
                }

                public function getCacheDir(): string
                {
                    return __DIR__ . '/var/' . (\getenv('GREENLIGHT_CHANNEL') ?: '0') . '/cache';
                }

                public function getLogDir(): string
                {
                    return __DIR__ . '/var/' . (\getenv('GREENLIGHT_CHANNEL') ?: '0') . '/log';
                }

                private function configureContainer(ContainerConfigurator $container): void
                {
                    $container->extension('framework', [
                        'secret' => 'probe',
                        'test' => true,
                        'http_method_override' => false,
                        'handle_all_throwables' => true,
                        'php_errors' => ['log' => true],
                    ]);

                    $services = $container->services()->defaults()->autowire()->autoconfigure();
                    $services->set(Greeter::class)->public();
                    $services->set(VisitCounter::class)->public();
                    $services->set('probe.named_greeter', NamedGreeter::class)->public();
                }

                private function configureRoutes(RoutingConfigurator $routes): void {}
            }
            PHP);

        $template = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SymfonyProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;
            use Greenlight\Symfony\Service;
            use Symfony\Component\HttpKernel\KernelInterface;

            final class %sTest
            {
                public function __construct(
                    private readonly Greeter $greeter,
                    #[Service('probe.named_greeter')] private readonly NamedGreeter $named,
                    private readonly KernelInterface $kernel,
                    private readonly VisitCounter $counter,
                ) {}

                #[Test]
                public function servicesComeFromTheContainer(): void
                {
                    $this->counter->record();

                    Expect::that($this->greeter->greet('Ada'))->toBe('Hello, Ada!')
                        ->and($this->named->greet())->toContain('probe.named_greeter')
                        ->and($this->kernel->getEnvironment())->toBe('test')
                        ->and($this->counter->count())->toBe(1);
                }

                #[Test]
                public function statefulServicesResetBetweenTests(): void
                {
                    // Without the services_resetter hook the shared counter
                    // would still hold the previous test's visit.
                    $this->counter->record();

                    Expect::that($this->counter->count())->toBe(1);
                }
            }
            PHP;

        foreach (['Alpha', 'Bravo'] as $name) {
            $project->write(\sprintf('tests/%sTest.php', $name), \sprintf($template, $name));
        }

        $project->write('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Symfony\SymfonyPlugin;

            require_once __DIR__ . '/probe.php';

            foreach (\glob(__DIR__ . '/tests/*Test.php') ?: [] as $file) {
                require_once $file;
            }

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(2)
                ->plugins(new SymfonyPlugin(\SymfonyProbe\ProbeKernel::class));
            PHP);

        return $project;
    }
}
