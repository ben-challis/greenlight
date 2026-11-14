<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

use function Greenlight\expect;

final readonly class AutomaticArtifactPruneOutputTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function automaticRetentionReportsEachPrunedRunOnStandardError(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'automatic-artifact-prune-output');
        $project->writeFile('tests/RetainedArtifactTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace AcceptanceArtifactPruneOutput;

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
        expect($first->exitCode)->toBe(0);
        expect($first->stderr)
            ->because('the first run has no older run to prune')
            ->not()->toContain('Pruned ');
        expect($second->exitCode)->toBe(0);
        expect($second->stderr)
            ->because('automatic retention MUST report each pruned run in the artifacts:prune format')
            ->toMatch('/^Pruned \\S+ \\(\\d+ bytes, count limit\\)\\.$/m');
    }
}
