<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class CustomReporterTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function configuredReportersUseSelectionOrderAndFreshRepeatState(): void
    {
        $project = $this->project('custom-reporter');
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Event\Event;
            use Greenlight\Plugin\ReporterProvider;
            use Greenlight\Reporting\Output\Output;
            use Greenlight\Reporting\Reporter;
            use Greenlight\Reporting\ReporterDefinition;

            require_once __DIR__ . '/tests/ProbeTest.php';

            final class OrderedReporterProvider implements ReporterProvider
            {
                private int $created = 0;

                public function reporters(): array
                {
                    return [
                        new ReporterDefinition(
                            'first',
                            function (Output $output): Reporter {
                                $instance = ++$this->created;

                                return new class ($output, 'first-' . $instance) implements Reporter {
                                    public function __construct(private Output $output, private string $label) {}

                                    public function onEvent(Event $event): void {}

                                    public function finish(): void
                                    {
                                        $this->output->write($this->label . "\n");
                                    }
                                };
                            },
                        ),
                        new ReporterDefinition(
                            'second',
                            static fn(Output $output): Reporter => new class ($output) implements Reporter {
                                public function __construct(private Output $output) {}

                                public function onEvent(Event $event): void {}

                                public function finish(): void
                                {
                                    $this->output->write("second\n");
                                }
                            },
                        ),
                    ];
                }
            }

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->plugins(
                    static fn(): OrderedReporterProvider => new OrderedReporterProvider(),
                );
            PHP);

        $result = GreenlightCli::run($project->directory, [
            'run',
            '--reporter=second',
            '--reporter=first',
            '--repeat=2',
            '--no-ansi',
        ]);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($result->stdout)
            ->because('reporters MUST finish in selection order with fresh state for each run')
            ->toContain("second\nfirst-1\n")
            ->toContain("second\nfirst-2\n");
        Expect::that($result->stderr)->toBe('');
    }

    #[Test]
    public function aCustomNameCannotReplaceABuiltInReporter(): void
    {
        $project = $this->project('duplicate-reporter');
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Plugin\ReporterProvider;
            use Greenlight\Reporting\Output\Output;
            use Greenlight\Reporting\PlainReporter;
            use Greenlight\Reporting\Reporter;
            use Greenlight\Reporting\ReporterDefinition;

            require_once __DIR__ . '/tests/ProbeTest.php';

            final class DuplicateBuiltInReporterProvider implements ReporterProvider
            {
                public function reporters(): array
                {
                    return [new ReporterDefinition(
                        'plain',
                        static fn(Output $output): Reporter => new PlainReporter($output),
                    )];
                }
            }

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->plugins(
                    static fn(): DuplicateBuiltInReporterProvider => new DuplicateBuiltInReporterProvider(),
                );
            PHP);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain', '--no-ansi']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->stderr)
            ->toBe('greenlight: Reporter name "plain" is registered more than one time.');
        Expect::that($result->stdout)
            ->because('a duplicate reporter name MUST fail before the test starts')
            ->toBe('');
    }

    #[Test]
    public function aFactoryFailureStopsBeforeTheTestStarts(): void
    {
        $project = $this->project('failed-reporter-factory');
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Plugin\ReporterProvider;
            use Greenlight\Reporting\Output\Output;
            use Greenlight\Reporting\Reporter;
            use Greenlight\Reporting\ReporterDefinition;

            require_once __DIR__ . '/tests/ProbeTest.php';

            final class FailingReporterFactoryProvider implements ReporterProvider
            {
                public function reporters(): array
                {
                    return [new ReporterDefinition(
                        'broken',
                        static fn(Output $output): Reporter => throw new RuntimeException('Connection failed'),
                    )];
                }
            }

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->plugins(
                    static fn(): FailingReporterFactoryProvider => new FailingReporterFactoryProvider(),
                );
            PHP);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=broken', '--no-ansi']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->stderr)
            ->toBe('greenlight: Reporter factory "broken" failed: Connection failed.');
        Expect::that($result->stdout)
            ->because('a reporter factory failure MUST stop before the test starts')
            ->toBe('');
    }

    #[Test]
    public function aProviderFailureStopsBeforeTheTestStarts(): void
    {
        $project = $this->project('failed-reporter-provider');
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Plugin\ReporterProvider;

            require_once __DIR__ . '/tests/ProbeTest.php';

            final class FailingReporterProvider implements ReporterProvider
            {
                public function reporters(): array
                {
                    throw new RuntimeException('Configuration failed');
                }
            }

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->plugins(
                    static fn(): FailingReporterProvider => new FailingReporterProvider(),
                );
            PHP);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain', '--no-ansi']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->stderr)
            ->toBe('greenlight: Reporter provider "FailingReporterProvider" failed: Configuration failed.');
        Expect::that($result->stdout)
            ->because('a reporter provider failure MUST stop before the test starts')
            ->toBe('');
    }

    private function project(string $name): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, $name);
        $project->writeFile('tests/ProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace CustomReporterProbe;

            use Greenlight\Attribute\Test;

            final class ProbeTest
            {
                #[Test]
                public function passes(): void {}
            }
            PHP);

        return $project;
    }
}
