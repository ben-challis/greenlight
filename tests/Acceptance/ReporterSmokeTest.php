<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class ReporterSmokeTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function junitProducesWellFormedXmlWithOneFailureAndOnePass(): void
    {
        $project = $this->writeProject();
        // Use standard output only. Extension messages on standard error
        // corrupt the document that the parser must accept as a complete unit.
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=junit']);
        Expect::that($result->exitCode)->because('JUnit produces well formed XML with one failure and one pass')->toBe(1);

        if ($result->stdout === '') {
            Fail::because('The JUnit reporter did not write XML to stdout.');
        }

        $document = new \DOMDocument();
        Expect::that($document->loadXML($result->stdout))->because('JUnit produces well formed XML with one failure and one pass')->toBeTrue();
        $testcases = $document->getElementsByTagName('testcase');
        Expect::that($testcases->length)->because('JUnit produces well formed XML with one failure and one pass')->toBe(2);
        $errors = $document->getElementsByTagName('error');
        Expect::that($errors->length)->because('JUnit produces well formed XML with one failure and one pass')->toBe(1);
        $failingClass = null;
        foreach ($testcases as $testcase) {
            if ($testcase->getAttribute('name') === 'fails') {
                $failingClass = $testcase->getAttribute('classname');
            }
        }
        Expect::that($failingClass)->because('JUnit produces well formed XML with one failure and one pass')->toBe('ReporterProbe\BadReporterProbeTest');
    }

    #[Test]
    public function logJunitWritesXmlToAFileAndKeepsPlainOutput(): void
    {
        $project = $this->writeProject();
        $report = $project->path('build/test-results/greenlight.junit.xml');
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain', '--log-junit=' . $report]);

        Expect::that($result->exitCode)->because('log JUnit writes XML to a file and keeps plain output')->toBe(1)
            ->and($result->stdout)->toContain('PASS ReporterProbe\GoodReporterProbeTest::passes')
            ->toContain('ERROR ReporterProbe\BadReporterProbeTest::fails')
            ->not()->toContain('<?xml');

        $xml = \file_get_contents($report);

        if ($xml === false || $xml === '') {
            Fail::because('Greenlight did not write a nonempty JUnit file.');
        }

        $document = new \DOMDocument();
        Expect::that($document->loadXML($xml))->because('log JUnit writes XML to a file and keeps plain output')->toBeTrue()
            ->and($document->getElementsByTagName('testcase')->length)->toBe(2);
    }

    #[Test]
    public function githubEmitsAWorkflowErrorCommandForTheFailingTest(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=github']);
        Expect::that($result->exitCode)->because('GitHub emits a workflow error command for the failing test')->toBe(1);
        // Use realpath(), not project->path(). The annotation contains the
        // absolute path that discovery reports after symbolic-link resolution.
        // On macOS, temporary paths can have aliases.
        $failingFile = (string) \realpath($project->path('tests/BadReporterProbeTest.php'));
        Expect::that($result->output())->because('GitHub emits a workflow error command for the failing test')->toContain('::error file=' . $failingFile)
            ->toContain('ReporterProbe\BadReporterProbeTest::fails')
            ->toContain('intentional reporter probe failure')
        // Passed tests do not add an annotation.
            ->not()->toContain('GoodReporterProbeTest');
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'reporter-smoke');

        $project->writeFile('tests/GoodReporterProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ReporterProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;

            final class GoodReporterProbeTest
            {
                #[Test]
                public function passes(): void
                {
                    Expect::that(true)->toBeTrue();
                }
            }
            PHP);

        $project->writeFile('tests/BadReporterProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ReporterProbe;

            use Greenlight\Attribute\Test;

            final class BadReporterProbeTest
            {
                #[Test]
                public function fails(): never
                {
                    throw new \RuntimeException('intentional reporter probe failure');
                }
            }
            PHP);

        $project->configureWithTestFiles([
            'tests/GoodReporterProbeTest.php',
            'tests/BadReporterProbeTest.php',
        ]);

        return $project;
    }
}
