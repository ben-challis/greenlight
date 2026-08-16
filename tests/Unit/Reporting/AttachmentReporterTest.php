<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\CapturedOutput;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\GithubReporter;
use Greenlight\Reporting\JsonLinesReporter;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Reporting\PlainReporter;
use Greenlight\Reporting\TeamCityReporter;
use Greenlight\Reporting\TtyReporter;

final class AttachmentReporterTest
{
    #[Test]
    public function humanReportersListMetadataWithoutInliningContent(): void
    {
        foreach ([
            static fn(BufferOutput $output) => new PlainReporter($output),
            static fn(BufferOutput $output) => new TtyReporter($output, color: false, cursor: false),
        ] as $factory) {
            $output = new BufferOutput();
            $reporter = $factory($output);
            $reporter->onEvent(new TestFinished($this->result(), 1.0));
            $reporter->finish();

            Expect::that($output->buffer())->toContain('response.json')
                ->toContain('application/json')
                ->toContain('build/greenlight-artifacts/run-1/response.json')
                ->not()->toContain('secret response body');
        }
    }

    #[Test]
    public function plainReporterRendersAttachmentsFromSuccessfulTestsOnce(): void
    {
        $output = new BufferOutput();
        $reporter = new PlainReporter($output);
        $fixture = $this->result();
        $passed = new TestResult(
            $fixture->id,
            Outcome::Passed,
            0.1,
            0,
            attachments: $fixture->attachments,
        );

        $reporter->onEvent(new TestFinished($passed, 1.0));
        $reporter->finish();

        Expect::that(\substr_count(
            $output->buffer(),
            'build/greenlight-artifacts/run-1/response.json',
        ))
            ->because('successful attachment metadata MUST be rendered exactly once')
            ->toBe(1);
        Expect::that($output->buffer())
            ->because('reporters MUST NOT inline attachment content')
            ->not()
            ->toContain('secret response body');
    }

    #[Test]
    public function plainReporterWritesSuccessfulAttachmentsWithoutRetainingTheResult(): void
    {
        $output = new BufferOutput();
        $reporter = new PlainReporter($output);
        $failed = $this->result();
        $result = new TestResult(
            $failed->id,
            Outcome::Passed,
            0.1,
            0,
            attachments: $failed->attachments,
        );
        $reference = \WeakReference::create($result);

        $reporter->onEvent(new TestFinished($result, 1.0));
        unset($result);
        \gc_collect_cycles();

        Expect::that($output->buffer())
            ->because('successful attachments are written before the result is released')
            ->toContain('PASS Example\AttachmentTest::fails')
            ->toContain('response.json')
            ->toContain('build/greenlight-artifacts/run-1/response.json');
        Expect::that($reference->get())
            ->because('the plain reporter does not retain successful results')
            ->toBeNull();
    }

    #[Test]
    public function ttyReporterDoesNotRetainSuccessfulResultsUntilFinish(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: false);
        $failed = $this->result();
        $result = new TestResult(
            $failed->id,
            Outcome::Passed,
            0.1,
            0,
            output: new CapturedOutput(\str_repeat('captured output', 10_000)),
            attachments: $failed->attachments,
        );
        $reference = \WeakReference::create($result);

        $reporter->onEvent(new TestFinished($result, 1.0));
        unset($result);
        \gc_collect_cycles();

        Expect::that($reference->get())->because('TTY reporter does not retain successful results until finish')->toBeNull();

        $reporter->finish();

        Expect::that($output->buffer())->because('TTY reporter does not retain successful results until finish')->toContain('Retained attachments from successful tests:')
            ->toContain('response.json');
    }

    #[Test]
    public function machineReportersExposePathsWithoutEmbeddingStorageState(): void
    {
        $json = new BufferOutput();
        $jsonl = new JsonLinesReporter($json);
        $jsonl->onEvent(new TestFinished($this->result(), 1.0));

        Expect::that($json->buffer())->because('machine reporters expose paths without embedding storage state')->toContain('"path":"build/greenlight-artifacts/run-1/response.json"')
            ->not()->toContain('"storageKey"');

        $xml = new BufferOutput();
        $junit = new JUnitReporter($xml);
        $junit->onEvent(new TestFinished($this->result(), 1.0));
        $junit->finish();

        Expect::that($xml->buffer())->because('machine reporters expose paths without embedding storage state')->toContain('[[ATTACHMENT|build/greenlight-artifacts/run-1/response.json]]');
    }

    #[Test]
    public function ciReportersLinkAndPublishTheArtifactDirectory(): void
    {
        $githubOutput = new BufferOutput();
        $github = new GithubReporter($githubOutput);
        $github->onEvent(new RunStarted('run-1', 1, 1, 0.0, 'build/greenlight-artifacts/run-1'));
        $github->onEvent(new TestFinished($this->result(), 1.0));
        $github->finish();

        Expect::that($githubOutput->buffer())->because('ci reporters link and publish the artifact directory')->toContain('response.json')
            ->toContain('::notice::Greenlight attachments');

        $teamCityOutput = new BufferOutput();
        $teamCity = new TeamCityReporter($teamCityOutput);
        $teamCity->onEvent(new RunStarted('run-1', 1, 1, 0.0, 'build/greenlight-artifacts/run-1'));
        $teamCity->onEvent(new TestFinished($this->result(), 1.0));
        $teamCity->finish();

        Expect::that($teamCityOutput->buffer())->because('ci reporters link and publish the artifact directory')->toContain("##teamcity[testMetadata")
            ->toContain("type='artifact'")
            ->toContain("##teamcity[publishArtifacts 'build/greenlight-artifacts/run-1']");
    }

    private function result(): TestResult
    {
        return new TestResult(
            new TestId('Example\AttachmentTest', 'fails'),
            Outcome::Failed,
            0.1,
            0,
            failures: [new FailureDetail('failed')],
            attachments: [
                new Attachment(
                    'response.json',
                    AttachmentKind::Value,
                    'application/json',
                    20,
                    \str_repeat('a', 64),
                    1,
                    'build/greenlight-artifacts/run-1/response.json',
                ),
            ],
        );
    }
}
