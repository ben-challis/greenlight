<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Tempest\Core\FrameworkKernel;

#[SkipUnless(ClassAvailable::class, FrameworkKernel::class)]
final readonly class TempestRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function bootsDiscoveryAndResetsTheApplicationBetweenTests(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)
            ->because(\sprintf(
                "the Tempest acceptance project MUST complete. Output:\n%s",
                $result->output(),
            ))
            ->toBe(0);
        Expect::that($result->output())->toContain('2 tests, 2 passed');
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'tempest');
        $root = \dirname(__DIR__, 2);

        $project->writeFile('composer.json', <<<'JSON'
            {
                "name": "greenlight/tempest-acceptance",
                "require": {
                    "tempest/framework": "^3.18"
                },
                "autoload": {
                    "psr-4": {
                        "TempestProbe\\": "app/"
                    }
                }
            }
            JSON);

        if (!\symlink($root . '/vendor', $project->path('vendor'))) {
            throw new \RuntimeException('The Tempest acceptance project could not link the vendor directory.');
        }

        $project->writeFile('app/GreetingConfig.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace TempestProbe;

            final readonly class GreetingConfig
            {
                public function __construct(public string $prefix) {}
            }
            PHP);

        $project->writeFile('app/Greeter.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace TempestProbe;

            use Tempest\Container\Singleton;

            #[Singleton]
            final readonly class Greeter
            {
                public function __construct(private GreetingConfig $config) {}

                public function greet(string $name): string
                {
                    return $this->config->prefix . ', ' . $name . '!';
                }
            }
            PHP);

        $project->writeFile('app/VisitCounter.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace TempestProbe;

            use Tempest\Container\Resettable;

            final class VisitCounter implements Resettable
            {
                public static int $resets = 0;

                private static int $visits = 0;

                public function record(): void
                {
                    ++self::$visits;
                }

                public function count(): int
                {
                    return self::$visits;
                }

                public function reset(): void
                {
                    ++self::$resets;
                    self::$visits = 0;
                }
            }
            PHP);

        $project->writeFile('app/LifecycleObserver.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace TempestProbe;

            use Tempest\Core\KernelEvent;
            use Tempest\EventBus\EventHandler;

            final class LifecycleObserver
            {
                public static int $shutdowns = 0;

                #[EventHandler(KernelEvent::SHUTDOWN)]
                public function onShutdown(): void
                {
                    ++self::$shutdowns;
                }
            }
            PHP);

        $project->writeFile('app/greeting.testing.config.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return new \TempestProbe\GreetingConfig('Hello from testing');
            PHP);

        $project->writeFile('tests/TempestApplicationTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace TempestProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;
            use Tempest\Container\Container;
            use Tempest\Core\Environment;
            use Tempest\Core\Kernel;
            use Tempest\Http\Method;
            use Tempest\Http\Request;

            final class TempestApplicationTest
            {
                public function __construct(
                    private readonly Greeter $greeter,
                    private readonly VisitCounter $counter,
                    private readonly Environment $environment,
                    private readonly Kernel $kernel,
                    private readonly Container $container,
                    private readonly Request $request,
                ) {}

                #[Test]
                public function discoveryLoadsTestingConfigurationAndContainerServices(): void
                {
                    $this->counter->record();

                    Expect::that($this->greeter->greet('Ada'))->toBe('Hello from testing, Ada!');
                    Expect::that($this->environment)->toBe(Environment::TESTING);
                    Expect::that($this->container->get(Greeter::class))->toBe($this->greeter);
                    Expect::that($this->kernel->internalStorage)->toContain('/.tempest/greenlight/1');
                    Expect::that($this->request->method)->toBe(Method::GET);
                    Expect::that($this->request->uri)->toBe('/');
                    Expect::that($this->counter->count())->toBe(1);
                }

                #[Test]
                public function shutdownAndContainerResetIsolateTheNextTest(): void
                {
                    Expect::that($this->counter->count())->toBe(0);
                    Expect::that(VisitCounter::$resets)->toBe(1);
                    Expect::that(LifecycleObserver::$shutdowns)->toBe(1);
                }
            }
            PHP);

        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Plugin\PluginDefinition;
            use Greenlight\Tempest\TempestPlugin;

            require_once __DIR__ . '/app/GreetingConfig.php';
            require_once __DIR__ . '/app/Greeter.php';
            require_once __DIR__ . '/app/VisitCounter.php';
            require_once __DIR__ . '/app/LifecycleObserver.php';
            require_once __DIR__ . '/tests/TempestApplicationTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->plugins(new PluginDefinition(
                    TempestPlugin::class,
                    static fn(): TempestPlugin => new TempestPlugin(__DIR__),
                ));
            PHP);

        return $project;
    }
}
