<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class Psr11SourceRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function namedContainersKeepServicesAndTestStateSeparate(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain', '--workers=1']);
        $output = $result->output();

        Expect::that($result->exitCode)
            ->because($output === '' ? 'The named PSR-11 source run returned no output.' : $output)
            ->toBe(0);
        Expect::that($output)->toContain('2 tests, 2 passed');
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'psr11-sources');
        $project->writeFile('application.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Psr11SourceProbe;

            final class Counter
            {
                public int $calls = 0;

                public function __construct(public readonly string $source) {}
            }

            PHP);

        $project->writeFile('tests/ContainerSourcesTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Psr11SourceProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;
            use Greenlight\Harness\Service;
            use Psr\Container\ContainerInterface;

            final readonly class ContainerSourcesTest
            {
                public function __construct(
                    #[Service(source: 'billing')] private Counter $billing,
                    #[Service('application.counter', source: 'legacy')] private Counter $legacy,
                    #[Service(source: 'billing')] private ContainerInterface $billingContainer,
                    #[Service(source: 'legacy')] private ContainerInterface $legacyContainer,
                    #[Service(source: 'billing')] private ContainerInterface $billingContainerAgain,
                ) {}

                #[Test]
                public function eachSourceUsesItsOwnServicesAndContainer(): void
                {
                    $this->checkFreshSources();
                }

                #[Test]
                public function eachSourceStartsFreshForTheNextTestAttempt(): void
                {
                    $this->checkFreshSources();
                }

                private function checkFreshSources(): void
                {
                    Expect::that($this->billing->source)->toBe('billing');
                    Expect::that($this->legacy->source)->toBe('legacy');
                    Expect::that($this->billingContainer)->not()->toBe($this->legacyContainer);
                    Expect::that($this->billingContainerAgain)->toBe($this->billingContainer);
                    Expect::that($this->billingContainer->get(Counter::class))->toBe($this->billing);
                    Expect::that($this->legacyContainer->get('application.counter'))->toBe($this->legacy);
                    Expect::that($this->billing->calls)->toBe(0);
                    Expect::that($this->legacy->calls)->toBe(0);

                    ++$this->billing->calls;

                    Expect::that($this->billing->calls)->toBe(1);
                    Expect::that($this->legacy->calls)->toBe(0);

                    ++$this->legacy->calls;
                }
            }

            PHP);

        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Psr11\Psr11Plugin;
            use Greenlight\Tests\Support\Psr11\ArrayContainer;
            use Psr\Container\ContainerInterface;
            use Psr11SourceProbe\Counter;

            require_once __DIR__ . '/application.php';
            require_once __DIR__ . '/tests/ContainerSourcesTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->plugins(
                    static fn(): Psr11Plugin => new Psr11Plugin(
                        static fn(): ContainerInterface => new ArrayContainer([
                            Counter::class => new Counter('billing'),
                            'application.counter' => new Counter('billing'),
                        ]),
                        source: 'billing',
                    ),
                    static fn(): Psr11Plugin => new Psr11Plugin(
                        static fn(): ContainerInterface => new ArrayContainer([
                            Counter::class => new Counter('legacy'),
                            'application.counter' => new Counter('legacy'),
                        ]),
                        source: 'legacy',
                    ),
                );

            PHP);

        return $project;
    }
}
