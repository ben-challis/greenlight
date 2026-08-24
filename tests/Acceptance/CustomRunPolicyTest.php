<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class CustomRunPolicyTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function configuredPolicyCanRejectAnOtherwiseSuccessfulRun(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'custom-run-policy');
        $project->writeFile('tests/PassingTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace CustomRunPolicy;

            use Greenlight\Attribute\Test;

            final readonly class PassingTest
            {
                #[Test]
                public function passes(): void {}
            }

            PHP);
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Plugin\RunAcceptancePolicy;
            use Greenlight\Result\ResultSummary;

            require_once __DIR__ . '/tests/PassingTest.php';

            final readonly class RequireNoPassedTests implements RunAcceptancePolicy
            {
                public function failureMessage(ResultSummary $summary, int $retriedPasses): ?string
                {
                    return $summary->passed > 0
                        ? sprintf(
                            'Project policy rejected %d passed test and %d retried passes.',
                            $summary->passed,
                            $retriedPasses,
                        )
                        : null;
                }
            }

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->plugins(static fn(): RequireNoPassedTests => new RequireNoPassedTests());

            PHP);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)
            ->because('the configured run policy MUST reject an otherwise successful run')
            ->toBe(1);
        Expect::that($result->output())
            ->toContain('1 test, 1 passed')
            ->toContain('Project policy rejected 1 passed test and 0 retried passes.');
    }
}
