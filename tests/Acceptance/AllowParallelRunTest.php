<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Event\TestClassStarted;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\JsonlEvents;

final readonly class AllowParallelRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function giantDataSetRunsOnMoreThanOneWorker(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'allow-parallel');
        $project->writeFile('tests/GiantDataSetTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace AllowParallelProbe;

            use Greenlight\Attribute\AllowParallel;
            use Greenlight\Attribute\DataSet;
            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;

            #[AllowParallel]
            final readonly class GiantDataSetTest
            {
                #[Test]
                #[DataSet('rows')]
                public function handlesRow(int $row): void
                {
                    \usleep(20_000);
                    Expect::that($row)->toBeGreaterThanOrEqual(0);
                }

                /** @return iterable<string, array{int}> */
                public static function rows(): iterable
                {
                    for ($row = 0; $row < 64; ++$row) {
                        yield 'row-' . $row => [$row];
                    }
                }
            }
            PHP);
        $project->configureWithTestFiles(['tests/GiantDataSetTest.php'], workers: 4);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=jsonl']);
        $workerIds = [];
        $classStarts = 0;

        foreach (JsonlEvents::from($result) as $event) {
            if (!$event instanceof TestClassStarted || $event->class !== 'AllowParallelProbe\GiantDataSetTest') {
                continue;
            }

            ++$classStarts;
            $workerIds[$event->workerId] = true;
        }

        Expect::that($result->exitCode)->because('the giant data-set run succeeds')->toBe(0);
        Expect::that(JsonlEvents::finishedTestIds($result))
            ->because('the split run MUST preserve every planned data set')
            ->toHaveCount(64);
        Expect::that($classStarts)
            ->because('each split data set MUST have one class-event bracket')
            ->toBe(64);
        Expect::that(\count($workerIds))
            ->because('the opt-in MUST execute one class on more than one worker process')
            ->toBeGreaterThan(1);
    }
}
