<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class RepeatReporterIsolationTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function eachIterationUsesAFreshReporter(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'repeat-reporter-isolation');
        $project->writeFile('tests/ProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace RepeatReporterIsolationProbe;

            use Greenlight\Attribute\Test;

            final class ProbeTest
            {
                #[Test]
                public function passes(): void {}
            }
            PHP);
        $project->configureWithTestFiles(['tests/ProbeTest.php']);

        $result = GreenlightCli::run($project->directory, [
            'run',
            '--reporter=junit',
            '--workers=1',
            '--no-ansi',
            '--repeat=2',
        ]);
        $output = $result->output();

        Expect::that($result->exitCode)
            ->because('repeated runs MUST complete with a fresh reporter for each iteration')
            ->toBe(0);
        Expect::that(\substr_count($output, '<?xml version="1.0" encoding="UTF-8"?>'))
            ->because('each iteration MUST emit its own JUnit document')
            ->toBe(2);
        Expect::that(\substr_count($output, '<testsuites name="greenlight" tests="1"'))
            ->because('each JUnit document MUST contain only its iteration')
            ->toBe(2);
        Expect::that($output)
            ->not()
            ->toContain('<testsuites name="greenlight" tests="2"');
    }
}
