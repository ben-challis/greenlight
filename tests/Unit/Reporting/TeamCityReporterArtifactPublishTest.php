<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\TeamCityReporter;

final class TeamCityReporterArtifactPublishTest
{
    #[Test]
    public function artifactDirectoryIsEscapedInThePublishCommand(): void
    {
        $output = new BufferOutput();
        $reporter = new TeamCityReporter($output);
        $id = new TestId('Example\AttachmentTest', 'passes');
        $attachment = new Attachment(
            'evidence.txt',
            AttachmentKind::File,
            'text/plain',
            8,
            \str_repeat('a', 64),
            1,
            'build/evidence.txt',
        );

        $reporter->onEvent(new RunStarted(
            'run-1',
            1,
            1,
            0.0,
            "build/greenlight|artifacts'\n\r[run]",
        ));
        $reporter->onEvent(new TestFinished(
            new TestResult($id, Outcome::Passed, 0.001, 0, attachments: [$attachment]),
            1.0,
        ));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('the TeamCity artifact command MUST escape its user-controlled directory')
            ->toBe(
                "##teamcity[testMetadata testName='Example\\AttachmentTest::passes' "
                . "name='attachment: evidence.txt' type='artifact' value='build/evidence.txt' "
                . "flowId='Example\\AttachmentTest']\n"
                . "##teamcity[testFinished name='Example\\AttachmentTest::passes' duration='1' "
                . "flowId='Example\\AttachmentTest']\n"
                . "##teamcity[publishArtifacts 'build/greenlight||artifacts|'|n|r|[run|]']\n",
            );
    }
}
