<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class ArtifactRunRetentionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function completedRunsApplyTheConfiguredPolicyAutomatically(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'automatic-artifact-retention');
        $project->writeFile('tests/RetainedArtifactTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace AcceptanceArtifactRetention;

            use Greenlight\Artifact\AttachmentRetention;
            use Greenlight\Artifact\Attachments;
            use Greenlight\Attribute\Test;

            final readonly class RetainedArtifactTest
            {
                public function __construct(private Attachments $attachments) {}

                #[Test]
                public function publishesEvidence(): void
                {
                    $this->attachments->text('evidence.txt', 'retained', retention: AttachmentRetention::Always);
                }
            }
            PHP);
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\ArtifactBuilder;
            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/RetainedArtifactTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts
                    ->directory(__DIR__ . '/artifacts')
                    ->maxCompletedRuns(1));
            PHP);

        $first = GreenlightCli::run($project->directory, ['run', '--no-ansi']);
        $second = GreenlightCli::run($project->directory, ['run', '--no-ansi']);
        $runs = \glob($project->path('artifacts/*'), \GLOB_ONLYDIR);
        $runs = $runs === false ? [] : $runs;

        Expect::that($first->exitCode)->toBe(0);
        Expect::that($second->exitCode)->toBe(0);
        Expect::that($runs)
            ->because('run completion MUST apply the configured completed-run count')
            ->toHaveCount(1);
        Expect::that(\is_file(($runs[0] ?? '') . '/.greenlight-run.json'))->toBeTrue();
    }

    #[Test]
    public function maintenanceCommandSupportsDryRunAndReportsDeletion(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'artifact-prune-command');
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\ArtifactBuilder;
            use Greenlight\Config\GreenlightConfig;

            return GreenlightConfig::create()
                ->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts
                    ->directory(__DIR__ . '/artifacts')
                    ->maxCompletedRuns(1));
            PHP);
        $this->writeCompletedRun($project->path('artifacts'), 'run-first', 10);
        $this->writeCompletedRun($project->path('artifacts'), 'run-second', 20);

        $dryRun = GreenlightCli::run($project->directory, ['artifacts:prune', '--dry-run', '--no-ansi']);
        $prune = GreenlightCli::run($project->directory, ['artifacts:prune', '--no-ansi']);

        Expect::that($dryRun->exitCode)->toBe(0);
        Expect::that($dryRun->stdout)->toContain('Would prune')->toContain('run-first')->toContain('count limit');
        Expect::that(\is_dir($project->path('artifacts/run-second')))->toBeTrue();
        Expect::that($prune->exitCode)->toBe(0);
        Expect::that($prune->stdout)->toContain('Pruned')->toContain('run-first');
        Expect::that(\is_dir($project->path('artifacts/run-first')))->toBeFalse();
    }

    #[Test]
    public function maintenanceCommandReportsEmptyAndInvalidConfigurations(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'artifact-prune-empty');
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            return GreenlightConfig::create();
            PHP);

        $noPolicy = GreenlightCli::run($project->directory, ['artifacts:prune', '--no-ansi']);
        $missingConfig = GreenlightCli::run($project->directory, [
            'artifacts:prune',
            '--config=missing.php',
            '--no-ansi',
        ]);
        $emptyDirectory = GreenlightCli::run($project->directory, [
            'artifacts:prune',
            '--artifacts-dir=',
            '--no-ansi',
        ]);

        Expect::that($noPolicy->exitCode)->toBe(0);
        Expect::that($noPolicy->stdout)->toContain('No artifact retention policy is configured.');
        Expect::that($missingConfig->exitCode)->toBe(1);
        Expect::that($missingConfig->output())->toContain('missing.php');
        Expect::that($emptyDirectory->exitCode)->toBe(64);
        Expect::that($emptyDirectory->output())->toContain('--artifacts-dir requires a value.');

        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\ArtifactBuilder;
            use Greenlight\Config\GreenlightConfig;

            return GreenlightConfig::create()
                ->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts
                    ->directory(__DIR__ . '/artifacts')
                    ->maxCompletedRuns(1));
            PHP);

        $dryRun = GreenlightCli::run($project->directory, ['artifacts:prune', '--dry-run', '--no-ansi']);
        $prune = GreenlightCli::run($project->directory, ['artifacts:prune', '--no-ansi']);

        Expect::that($dryRun->stdout)->toContain('No completed artifact runs would be pruned.');
        Expect::that($prune->stdout)->toContain('No completed artifact runs were pruned.');
    }

    #[Test]
    public function maintenanceCommandReportsANoncanonicalParent(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'artifact-prune-warning');
        \mkdir($project->path('artifacts'));
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\ArtifactBuilder;
            use Greenlight\Config\GreenlightConfig;

            return GreenlightConfig::create()
                ->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts
                    ->directory(__DIR__ . '/artifacts/.')
                    ->maxCompletedRuns(1));
            PHP);

        $result = GreenlightCli::run($project->directory, ['artifacts:prune', '--no-ansi']);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($result->stdout)->toContain('No completed artifact runs were pruned.');
        Expect::that($result->stderr)
            ->toContain('Greenlight did not prune artifacts because the artifact parent is not canonical.');
    }

    private function writeCompletedRun(string $parent, string $runId, int $completedAt): void
    {
        if (!\is_dir($parent)) {
            \mkdir($parent, 0o700, true);
        }
        $directory = $parent . '/' . $runId;
        \mkdir($directory, 0o700);
        \file_put_contents($directory . '/evidence.txt', $runId);
        \file_put_contents($directory . '/.greenlight-run.lock', '');
        \file_put_contents($directory . '/.greenlight-run.json', \json_encode([
            'version' => 1,
            'owner' => 'greenlight',
            'runId' => $runId,
            'state' => 'completed',
            'startedAt' => 1,
            'completedAt' => $completedAt,
            'files' => [
                'evidence.txt' => [
                    'bytes' => \strlen($runId),
                    'sha256' => \hash('sha256', $runId),
                ],
            ],
        ], \JSON_THROW_ON_ERROR));
    }
}
