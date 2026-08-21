<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class Psr11RunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function injectsServicesFromAFreshPsr11ContainerPerTest(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain', '--workers=1']);

        $output = $result->output();
        Expect::that($result->exitCode)
            ->because($output === '' ? 'The PSR-11 acceptance run returned no output.' : $output)
            ->toBe(0);
        Expect::that($output)->toContain('2 tests, 2 passed');
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'psr11');

        $project->writeFile('application.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Psr11Probe;

            final readonly class Greeter
            {
                public function __construct(private string $greeting) {}

                public function greet(string $name): string
                {
                    return $this->greeting . ', ' . $name . '!';
                }
            }

            final class VisitCounter
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
            }

            PHP);

        $project->writeFile('tests/ContainerServicesTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Psr11Probe;

            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;
            use Greenlight\Psr\Service;

            final readonly class ContainerServicesTest
            {
                public function __construct(
                    private Greeter $greeter,
                    #[Service('probe.named_greeter')] private Greeter $namedGreeter,
                    private VisitCounter $counter,
                ) {}

                #[Test]
                public function resolvesServicesByTypeAndExplicitId(): void
                {
                    $this->counter->record();

                    Expect::that($this->greeter->greet('Ada'))->toBe('Hello, Ada!');
                    Expect::that($this->namedGreeter->greet('Grace'))->toBe('Welcome, Grace!');
                    Expect::that($this->counter->count())->toBe(1);
                }

                #[Test]
                public function createsFreshServiceStateForTheNextTestAttempt(): void
                {
                    $this->counter->record();

                    Expect::that($this->counter->count())->toBe(1);
                }
            }

            PHP);

        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Plugin\PluginDefinition;
            use Greenlight\Psr\Psr11Plugin;
            use Greenlight\Tests\Support\Psr\ArrayContainer;
            use Psr\Container\ContainerInterface;
            use Psr11Probe\Greeter;
            use Psr11Probe\VisitCounter;

            require_once __DIR__ . '/application.php';
            require_once __DIR__ . '/tests/ContainerServicesTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->plugins(new PluginDefinition(
                    Psr11Plugin::class,
                    static fn(): Psr11Plugin => new Psr11Plugin(
                        static fn(): ContainerInterface => new ArrayContainer([
                            Greeter::class => new Greeter('Hello'),
                            'probe.named_greeter' => new Greeter('Welcome'),
                            VisitCounter::class => new VisitCounter(),
                        ]),
                    ),
                ));

            PHP);

        return $project;
    }
}
