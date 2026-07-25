<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class ResourceSchedulingTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function limitsAResourceWithoutSerializingDisjointWork(): void
    {
        $project = $this->concurrencyProject();
        $state = $project->path('state.json');
        $environment = ['RESOURCE_PROBE_STATE' => $state];

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain'], $environment);
        $counters = $this->counters($state);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($counters['postgres'])->toBe(2);
        Expect::that($counters['global'])->toBeGreaterThanOrEqual(3);

        \file_put_contents($state, '{}');

        $overridden = GreenlightCli::run(
            $project->directory,
            ['run', '--reporter=plain', '--resource-limit=postgres=1'],
            $environment,
        );
        $counters = $this->counters($state);

        Expect::that($overridden->exitCode)->toBe(0);
        Expect::that($counters['postgres'])->toBe(1);
        Expect::that($counters['global'])->toBeGreaterThanOrEqual(2);
    }

    #[Test]
    public function aCrashedWorkerReleasesItsLeaseForWaitingWork(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'resource-crash');
        $marker = $project->path('survivor-ran');

        $project->write('tests/ACrashTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ResourceCrashProbe;

            use Greenlight\Attribute\RequiresResource;
            use Greenlight\Attribute\Test;

            #[RequiresResource('sandbox')]
            final class ACrashTest
            {
                #[Test]
                public function crashes(): never
                {
                    exit(17);
                }
            }
            PHP);
        $project->write('tests/BSurvivorTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ResourceCrashProbe;

            use Greenlight\Attribute\RequiresResource;
            use Greenlight\Attribute\Test;

            #[RequiresResource('sandbox')]
            final class BSurvivorTest
            {
                #[Test]
                public function runsAfterTheCrash(): void
                {
                    $marker = \getenv('RESOURCE_CRASH_MARKER');

                    if (!\is_string($marker) || $marker === '') {
                        throw new \RuntimeException('Missing crash marker path.');
                    }

                    \file_put_contents($marker, 'ran');
                }
            }
            PHP);
        $project->write('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            foreach (\glob(__DIR__ . '/tests/*Test.php') ?: [] as $file) {
                require_once $file;
            }

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(2)
                ->resourceLimit('sandbox');
            PHP);

        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--reporter=plain'],
            ['RESOURCE_CRASH_MARKER' => $marker],
        );

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())->toContain('2 tests, 1 passed, 1 errored');
        Expect::that((string) \file_get_contents($marker))->toBe('ran');
    }

    private function concurrencyProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'resource-concurrency');
        $project->write('tests/Probe.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ResourceConcurrencyProbe;

            final class Probe
            {
                public static function hold(string $resource): void
                {
                    self::change($resource, 1);
                    \usleep(400_000);
                    self::change($resource, -1);
                }

                private static function change(string $resource, int $delta): void
                {
                    $path = \getenv('RESOURCE_PROBE_STATE');

                    if (!\is_string($path) || $path === '') {
                        throw new \RuntimeException('Missing resource probe state path.');
                    }

                    $lock = \fopen($path . '.lock', 'c+');

                    if (!\is_resource($lock) || !\flock($lock, \LOCK_EX)) {
                        throw new \RuntimeException('Could not lock resource probe state.');
                    }

                    try {
                        $decoded = \is_file($path) ? \json_decode((string) \file_get_contents($path), true) : [];
                        $state = \is_array($decoded) ? $decoded : [];
                        $current = \is_array($state['current'] ?? null) ? $state['current'] : [];
                        $max = \is_array($state['max'] ?? null) ? $state['max'] : [];

                        foreach ([$resource, 'global'] as $name) {
                            $current[$name] = (int) ($current[$name] ?? 0) + $delta;
                            $max[$name] = \max((int) ($max[$name] ?? 0), $current[$name]);
                        }

                        \file_put_contents($path, \json_encode(['current' => $current, 'max' => $max], \JSON_THROW_ON_ERROR));
                    } finally {
                        \flock($lock, \LOCK_UN);
                        \fclose($lock);
                    }
                }
            }
            PHP);

        foreach (['AFirst', 'BSecond', 'CThird', 'DFourth'] as $class) {
            $project->write('tests/' . $class . 'Test.php', \sprintf(
                <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace ResourceConcurrencyProbe;

                use Greenlight\Attribute\RequiresResource;
                use Greenlight\Attribute\Test;

                #[RequiresResource('postgres')]
                final class %sTest
                {
                    #[Test]
                    public function holdsPostgres(): void
                    {
                        Probe::hold('postgres');
                    }
                }
                PHP,
                $class,
            ));
        }

        $project->write('tests/EDisjointTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ResourceConcurrencyProbe;

            use Greenlight\Attribute\Test;

            final class EDisjointTest
            {
                #[Test]
                public function overlapsTheLimitedWork(): void
                {
                    Probe::hold('other');
                }
            }
            PHP);
        $project->write('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/Probe.php';

            foreach (\glob(__DIR__ . '/tests/*Test.php') ?: [] as $file) {
                require_once $file;
            }

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(4)
                ->resourceLimit('postgres', 2);
            PHP);

        return $project;
    }

    /**
     * @return array{postgres: int, global: int}
     */
    private function counters(string $path): array
    {
        $decoded = \json_decode((string) \file_get_contents($path), true, 16, \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded) || !\is_array($decoded['max'] ?? null)) {
            throw new \RuntimeException('Resource probe did not write maximum concurrency counters.');
        }

        $postgres = $decoded['max']['postgres'] ?? 0;
        $global = $decoded['max']['global'] ?? 0;

        if (!\is_int($postgres) || !\is_int($global)) {
            throw new \RuntimeException('Resource probe counters must be integers.');
        }

        return [
            'postgres' => $postgres,
            'global' => $global,
        ];
    }
}
