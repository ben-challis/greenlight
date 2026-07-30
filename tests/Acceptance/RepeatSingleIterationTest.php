<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class RepeatSingleIterationTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function oneIterationUsesTheStandardRunOutput(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'repeat-single-iteration');
        $project->writeFile('tests/ProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace RepeatSingleIterationProbe;

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
            '--reporter=plain',
            '--workers=1',
            '--no-ansi',
            '--repeat=1',
        ]);

        Expect::that($result->exitCode)
            ->because('one requested iteration MUST complete as a standard run')
            ->toBe(0);
        Expect::that($result->output())
            ->because('one requested iteration MUST omit repeat-loop output')
            ->toContain('1 test, 1 passed')
            ->not()
            ->toContain('Repeat:');
    }
}
