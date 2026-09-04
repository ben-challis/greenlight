<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Event\RunFinished;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Internal\Event\EventCodec;
use Greenlight\Result\Outcome;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\TestId;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class ProfileReportMemoryTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    public function aLargeSavedStreamCanBeProfiledWithALowerMemoryLimit(): void
    {
        $directory = $this->temporaryDirectory->subdirectory('profile-memory');
        $path = $directory . '/profile.jsonl';
        $stream = \fopen($path, 'wb');
        if ($stream === false) {
            Fail::because('Cannot create the profile fixture.');
        }

        try {
            \fwrite($stream, EventCodec::encodeJsonLine(new RunStarted('large-stream', 40_000, 1, 1.0)));

            for ($index = 0; $index < 40_000; ++$index) {
                $id = new TestId('Example\\SuiteTest', 'probe', \str_repeat('x', 500) . $index);
                \fwrite($stream, EventCodec::encodeJsonLine(new TestFinished(
                    new TestResult($id, Outcome::Passed, 0.001, 1),
                    1.0,
                )));
            }

            \fwrite($stream, \rtrim(EventCodec::encodeJsonLine(new RunFinished(
                'large-stream',
                new ResultSummary(passed: 40_000),
                1.0,
                2.0,
            )), "\n"));
        } finally {
            \fclose($stream);
        }

        Expect::that(\filesize($path))->toBeGreaterThan(32 * 1024 * 1024);
        $result = GreenlightCli::run($directory, ['profile:report', '--input=profile.jsonl', '--no-ansi'], phpArguments: ['-d', 'memory_limit=32M']);
        Expect::that($result->exitCode)->toBe(0);
        Expect::that($result->stdout)->toBe("Profile:\n  Workers: 1 requested, 0 spawned");
    }
}
