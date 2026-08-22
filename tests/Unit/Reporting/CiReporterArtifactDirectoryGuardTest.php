<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Artifact\Attachment;
use Greenlight\Artifact\AttachmentKind;
use Greenlight\Attribute\Test;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\GithubReporter;
use Greenlight\Reporting\TeamCityReporter;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

final class CiReporterArtifactDirectoryGuardTest
{
    #[Test]
    public function githubDoesNotAnnounceAMissingArtifactDirectory(): void
    {
        $output = new BufferOutput();
        $reporter = new GithubReporter($output);
        $reporter->onEvent(new RunStarted('run-1', 1, 1, 0.0));
        $reporter->onEvent(new TestFinished($this->result(), 1.0));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('GitHub MUST NOT announce an artifact directory that the run did not create')
            ->toBe('');
    }

    #[Test]
    public function teamCityDoesNotPublishAMissingArtifactDirectory(): void
    {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);
        $reporter->onEvent(new RunStarted('run-1', 1, 1, 0.0));
        $reporter->onEvent(new TestFinished($this->result(), 1.0));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('TeamCity MUST retain attachment metadata without an artifact publication command')
            ->toBe(
                "##teamcity[testMetadata testName='Example\\AttachmentTest::passes' "
                . "name='attachment: evidence.txt' type='artifact' value='build/evidence.txt' "
                . "flowId='Example\\AttachmentTest']\n"
                . "##teamcity[testFinished name='Example\\AttachmentTest::passes' duration='100' "
                . "flowId='Example\\AttachmentTest']\n",
            );
    }

    private function result(): TestResult
    {
        return new TestResult(
            new TestId('Example\AttachmentTest', 'passes'),
            Outcome::Passed,
            0.1,
            0,
            attachments: [
                new Attachment(
                    'evidence.txt',
                    AttachmentKind::File,
                    'text/plain',
                    8,
                    \str_repeat('a', 64),
                    1,
                    'build/evidence.txt',
                ),
            ],
        );
    }
}
