<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\PhpSubprocess;

use function Greenlight\expect;

final readonly class ExpectFunctionRunTest
{
    public function __construct(private TemporaryDirectory $directory, private Cleanup $cleanup) {}

    #[Test]
    #[DataRow([1])]
    #[DataRow([2])]
    public function runnerLoadsTheHelperBeforeDiscoveryAndWorkerExecution(int $workers): void
    {
        $project = $this->project();
        $result = GreenlightCli::run($project->directory, ['run', '--workers=' . $workers, '--reporter=plain']);

        expect($result->exitCode)->because('Runner output: ' . $result->output())->toBe(0);
        expect($result->output())->toContain('1 test, 1 passed');
    }

    #[Test]
    public function watchProcessesLoadTheHelperOnEachRun(): void
    {
        $project = $this->project();
        $process = GreenlightCli::start($project->directory, ['run', '--watch', '--workers=1', '--reporter=plain']);
        $this->cleanup->defer($process->terminate(...));
        $process->readStdoutUntil('Waiting for changes', 20.0);
        $process->write("\n");
        $process->readStdoutUntil('Waiting for changes', 20.0);
        $process->write('q');
        $result = $process->wait(10.0);

        expect($result->exitCode)->because('Watch output: ' . $result->output())->toBe(0);
        expect(\substr_count($result->stdout, '1 test, 1 passed'))->toBe(2);
    }

    #[Test]
    public function composerLeavesTheHelperUnloadedAndExplicitIncludesAreIdempotent(): void
    {
        $result = PhpSubprocess::run(\dirname(__DIR__, 2), ['-r', <<<'PHP'
            require 'vendor/autoload.php';

            function expect(mixed $value): mixed
            {
                return $value;
            }

            if (function_exists('Greenlight\\expect')) {
                exit(1);
            }

            require_once 'src/Expect/functions.php';
            require_once 'src/Expect/functions.php';

            \Greenlight\expect(expect('separate'))->toBe('separate');
            PHP]);

        expect($result->exitCode)->because('PHP output: ' . $result->output())->toBe(0);
    }

    private function project(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->directory, 'expect-function');
        $project->writeFile('tests/FunctionTest.php', <<<'PHP'
            <?php

            namespace ExpectFunctionFixture;

            use Greenlight\Attribute\DataSet;
            use Greenlight\Attribute\Test;
            use function Greenlight\expect;

            expect('declaration')->toBe('declaration');

            final class FunctionTest
            {
                #[Test]
                #[DataSet('values')]
                public function checksValue(string $value): void
                {
                    expect($value)->toBe('worker');
                    expect()->calling(static fn(): string => $value)->toReturn('worker');
                }

                public static function values(): iterable
                {
                    expect('provider')->toBe('provider');

                    yield ['worker'];
                }
            }
            PHP);
        $project->configureWithTestFiles(['tests/FunctionTest.php']);

        return $project;
    }
}
