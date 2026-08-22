<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestFinished;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\Artifact\PublishingEventSink;
use Greenlight\Execution\Artifact\TestArtifactBudget;
use Greenlight\Expect\Expect;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\TestId;
use Greenlight\Tests\Support\CollectingEventSink;

final readonly class PublishingEventSinkTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function completedTestsPublishAttachmentsBeforeTheyReachTheInnerSink(): void
    {
        $root = $this->tempDirectory->subdirectory('publishing-sink');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-publishing-sink');
        $this->cleanup->defer($store->cleanup(...));
        $inner = new CollectingEventSink();
        $sink = new PublishingEventSink($store, $inner);
        $id = new TestId('Example\EvidenceTest', 'fails');
        $attempt = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attempt->text('evidence.txt', 'published evidence');
        $result = new TestResult(
            $id,
            Outcome::Failed,
            0.1,
            0,
            attachments: $attempt->seal(),
        );
        $started = new RunStarted('run-publishing-sink', 1, 1, 10.0);

        $sink->emit($started);
        $sink->emit(new TestFinished($result, 11.0));

        Expect::that($inner->sequence())
            ->because('the publishing sink MUST preserve event order')
            ->toBe(['RunStarted', 'TestFinished']);
        Expect::that($inner->events[0])
            ->because('events without test results MUST pass through unchanged')
            ->toBe($started);

        $finished = $inner->events[1];

        Expect::that($finished)
            ->because('The second event MUST be TestFinished.')
            ->toBeInstanceOf(TestFinished::class);

        $publishedPath = $finished->result->attachments[0]->path;

        Expect::that($finished->result)
            ->because('a completed event MUST replace its staged result with the published result')
            ->not()
            ->toBe($result);
        Expect::that($finished->occurredAt)
            ->because('publishing MUST preserve the event timestamp')
            ->toBe(11.0);
        Expect::that($finished->result->attachments)
            ->because('the inner sink MUST receive published attachment metadata')
            ->toHaveCount(1);
        Expect::that($finished->result->attachments[0]->path)
            ->toContain('run-publishing-sink');
        Expect::that((string) \file_get_contents(
            \str_starts_with($publishedPath, '/') ? $publishedPath : $root . '/' . $publishedPath,
        ))
            ->toBe('published evidence');
    }
}
