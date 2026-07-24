<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Drives --reporter=junit and --reporter=github end to end against a project
 * with one passing and one failing test.
 *
 * Both reporters write to stdout; neither takes a file path option, so the
 * subprocess's stdout is the document to parse.
 */
final readonly class ReporterSmokeTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function junitProducesWellFormedXmlWithOneFailureAndOnePass(): void
    {
        $project = $this->writeProject();
        // Stdout only: extension noise on stderr would corrupt the
        // document the parse below must accept whole.
        [$exit, $output] = $project->runStdout('run', '--reporter=junit');
        Expect::that($exit)->toBe(1);
        $document = new \DOMDocument();
        Expect::that($document->loadXML($output))->toBeTrue();
        $testcases = $document->getElementsByTagName('testcase');
        Expect::that($testcases->length)->toBe(2);
        $errors = $document->getElementsByTagName('error');
        Expect::that($errors->length)->toBe(1);
        $failingCase = null;
        foreach ($testcases as $testcase) {
            if ($testcase->getAttribute('name') === 'fails') {
                $failingCase = $testcase;
            }
        }
        if (!$failingCase instanceof \DOMElement) {
            throw new \RuntimeException('Expected the junit output to include a testcase named "fails".');
        }
        Expect::that($failingCase->getAttribute('classname'))->toBe('ReporterProbe\BadReporterProbeTest');
    }

    #[Test]
    public function githubEmitsAWorkflowErrorCommandForTheFailingTest(): void
    {
        $project = $this->writeProject();
        [$exit, $output] = $project->run('run', '--reporter=github');
        Expect::that($exit)->toBe(1);
        // realpath(), not project->path(): the annotation carries the
        // symlink-resolved absolute path discovery reported (macOS temp
        // dirs alias /var/folders/... to /private/var/folders/...).
        $failingFile = (string) \realpath($project->path('tests/BadReporterProbeTest.php'));
        Expect::that($output)->toContain('::error file=' . $failingFile)
            ->and($output)->toContain('ReporterProbe\BadReporterProbeTest::fails')
            ->and($output)->toContain('intentional reporter probe failure')
            // Passing tests add no annotation.
            ->and($output)->not()->toContain('GoodReporterProbeTest');
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'reporter-smoke');

        $project->write('tests/GoodReporterProbeTest.php', <<<'PHP'
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

        $project->write('tests/BadReporterProbeTest.php', <<<'PHP'
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

        $project->writeConfig([
            'tests/GoodReporterProbeTest.php',
            'tests/BadReporterProbeTest.php',
        ]);

        return $project;
    }
}
