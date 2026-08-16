<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Illuminate\Foundation\Application;

#[SkipUnless(ClassAvailable::class, Application::class)]
final readonly class LaravelRunTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function injectsContainerServicesFromAFreshApplicationPerTest(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);
        Expect::that($result->exitCode)->toBe(0);
        Expect::that($result->output())->toContain('4 tests, 4 passed');
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'laravel');

        $project->writeFile('probe.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace LaravelProbe;

            use Greenlight\Tests\Fixture\Laravel\Greeter;
            use Greenlight\Tests\Fixture\Laravel\NamedGreeter;
            use Greenlight\Tests\Fixture\Laravel\VisitCounter;
            use Illuminate\Support\ServiceProvider;

            // Greeter, NamedGreeter, and VisitCounter are the committed
            // Greenlight\Tests\Fixture\Laravel service fixtures: this
            // process autoloads them from the repository's own
            // autoload-dev PSR-4 map, and they are never in a scanned
            // discovery path, so reusing them here carries no risk of a
            // loaded-from-the-wrong-file conflict.
            final class ProbeServiceProvider extends ServiceProvider
            {
                public function register(): void
                {
                    $this->app->singleton(Greeter::class);
                    $this->app->singleton(VisitCounter::class);
                    $this->app->singleton('probe.named_greeter', static fn(): NamedGreeter => new NamedGreeter());
                }
            }
            PHP);

        $project->writeFile('bootstrap/app.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Illuminate\Foundation\Application;

            $base = __DIR__ . '/../var/' . (\getenv('GREENLIGHT_CHANNEL') ?: '0');

            if (!\is_dir($base . '/bootstrap/cache')) {
                \mkdir($base . '/bootstrap/cache', 0o777, true);
            }

            return Application::configure(basePath: $base)
                ->withProviders([\LaravelProbe\ProbeServiceProvider::class])
                ->create();
            PHP);

        $template = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace LaravelProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;
            use Greenlight\Laravel\Service;
            use Greenlight\Tests\Fixture\Laravel\Greeter;
            use Greenlight\Tests\Fixture\Laravel\NamedGreeter;
            use Greenlight\Tests\Fixture\Laravel\VisitCounter;
            use Illuminate\Contracts\Foundation\Application;

            final class %sTest
            {
                public function __construct(
                    private readonly Greeter $greeter,
                    #[Service('probe.named_greeter')] private readonly NamedGreeter $named,
                    private readonly Application $app,
                    private readonly VisitCounter $counter,
                ) {}

                #[Test]
                public function servicesComeFromTheContainer(): void
                {
                    $this->counter->record();

                    Expect::that($this->greeter->greet('Ada'))->toBe('Hello, Ada!');
                    Expect::that($this->named->greet())->toContain('fixture.named_greeter');
                    Expect::that($this->app->environment())->toBe('testing');
                    Expect::that($this->counter->count())->toBe(1);
                }

                #[Test]
                public function aFreshApplicationIsolatesStatefulSingletons(): void
                {
                    // With one shared application per worker the singleton
                    // counter would still hold the previous test's visit.
                    $this->counter->record();

                    Expect::that($this->counter->count())->toBe(1);
                }
            }
            PHP;

        foreach (['Alpha', 'Bravo'] as $name) {
            $project->writeFile(\sprintf('tests/%sTest.php', $name), \sprintf($template, $name));
        }

        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Laravel\LaravelPlugin;

            require_once __DIR__ . '/probe.php';

            foreach (\glob(__DIR__ . '/tests/*Test.php') ?: [] as $file) {
                require_once $file;
            }

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(2)
                ->plugins(new LaravelPlugin(__DIR__ . '/bootstrap/app.php'));
            PHP);

        return $project;
    }
}
