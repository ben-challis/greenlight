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
use Greenlight\Reporting\GithubReporter;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\TeamCityReporter;

final class CiReporterAttachmentRetentionTest
{
    #[Test]
    public function laterResultsDoNotEraseTheNeedToPublishEarlierAttachments(): void
    {
        $githubOutput = new BufferOutput();
        $this->feed(new GithubReporter($githubOutput));

        Expect::that($githubOutput->buffer())
            ->because('GitHub output MUST retain the publication notice for an earlier attachment')
            ->toContain('::notice::Greenlight attachments: build/greenlight-artifacts/run-1');

        $teamCityOutput = new BufferOutput();
        $this->feed(new TeamCityReporter($teamCityOutput));

        Expect::that($teamCityOutput->buffer())
            ->because('TeamCity output MUST retain the publication command for an earlier attachment')
            ->toContain("##teamcity[publishArtifacts 'build/greenlight-artifacts/run-1']");
    }

    private function feed(Reporter $reporter): void
    {
        $reporter->onEvent(new RunStarted(
            'run-1',
            2,
            1,
            1_750_000_000.0,
            'build/greenlight-artifacts/run-1',
        ));
        $reporter->onEvent(new TestFinished($this->result('hasAttachment', true), 1_750_000_000.1));
        $reporter->onEvent(new TestFinished($this->result('hasNoAttachment', false), 1_750_000_000.2));
        $reporter->finish();
    }

    /**
     * @param non-empty-string $method
     */
    private function result(string $method, bool $attached): TestResult
    {
        $attachments = $attached
            ? [new Attachment(
                'response.json',
                AttachmentKind::File,
                'application/json',
                2,
                \str_repeat('0', 64),
                1,
                'build/greenlight-artifacts/run-1/response.json',
            )]
            : [];

        return new TestResult(
            new TestId('Acme\\AttachmentTest', $method),
            Outcome::Passed,
            0.01,
            0,
            attachments: $attachments,
        );
    }
}
