<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Artifact\AttachmentRetention;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;

final readonly class AttachmentsTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function stagesPublishesAndHashesEveryAttachmentKind(): void
    {
        $root = $this->tempDirectory->subdirectory('published');
        $configuration = new ArtifactConfiguration($root);
        $store = ArtifactStore::open($configuration, $root, 'run-1');
        $attachments = $store->forAttempt(new TestId('Example\EvidenceTest', 'fails'), 1, new TestArtifactBudget());
        $source = $this->tempDirectory->path() . '/source.bin';
        \file_put_contents($source, "\x00file");

        $attachments->value('request.json', ['ok' => true]);
        $attachments->text('response.txt', 'hello');
        $attachments->bytes('packet.bin', "\x00\xFF");
        $attachments->text('response.txt', 'duplicate name');
        $attachments->file('copy.bin', $source);
        \unlink($source);

        $retained = $attachments->seal();
        $published = $store->publish(new TestResult(
            new TestId('Example\EvidenceTest', 'fails'),
            Outcome::Failed,
            0.1,
            0,
            attachments: $retained,
        ));

        Expect::that($published->attachments)->because('stages publishes and hashes every attachment kind')->toHaveCount(5)
            ->and($published->attachments[1]->name)->toBe('response.txt')
            ->and($published->attachments[3]->name)->toBe('response.txt')
            ->and($published->attachments[3]->path)->toContain('response-2.txt');

        foreach ($published->attachments as $attachment) {
            $path = $this->absolute($root, $attachment->path);
            Expect::that(\is_file($path))->toBeTrue()
                ->and(\hash_file('sha256', $path))->toBe($attachment->sha256);
        }

        Expect::that((string) \file_get_contents($this->absolute($root, $published->attachments[0]->path)))->because('stages publishes and hashes every attachment kind')
            ->toBe("{\"ok\":true}\n");
        Expect::that((string) \file_get_contents($this->absolute($root, $published->attachments[4]->path)))->because('stages publishes and hashes every attachment kind')
            ->toBe("\x00file");

        $store->cleanup();
    }

    #[Test]
    public function passingAttemptsDiscardOnFailureAttachmentsButKeepAlways(): void
    {
        $root = $this->tempDirectory->subdirectory('retention');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-2');
        $attachments = $store->forAttempt(new TestId('Example\EvidenceTest', 'passes'), 1, new TestArtifactBudget());
        $attachments->text('discarded.txt', 'discard me');
        $attachments->text('kept.txt', 'keep me', retention: AttachmentRetention::Always);

        $published = $store->publish(new TestResult(
            new TestId('Example\EvidenceTest', 'passes'),
            Outcome::Passed,
            0.1,
            0,
            attachments: $attachments->seal(),
        ));

        Expect::that($published->attachments)->because('passing attempts discard on failure attachments but keep always')->toHaveCount(1)
            ->and($published->attachments[0]->name)->toBe('kept.txt');

        $store->cleanup();
    }

    #[Test]
    public function outputDirectoryIsCreatedOnlyWhenEvidenceIsPublished(): void
    {
        $root = $this->tempDirectory->subdirectory('lazy-output');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-lazy');
        $output = $store->publicDirectory();

        Expect::that(\file_exists($output))->because('output directory is created only when evidence is published')->toBeFalse();

        $id = new TestId('Example\EvidenceTest', 'passes');
        $attachments = $store->forAttempt($id, 1, new TestArtifactBudget());

        Expect::that(\file_exists($output))->because('output directory is created only when evidence is published')->toBeFalse();

        $attachments->text('discarded.txt', 'discard me');
        $published = $store->publish(new TestResult(
            $id,
            Outcome::Passed,
            0.1,
            0,
            attachments: $attachments->seal(),
        ));

        Expect::that($published->attachments)->because('output directory is created only when evidence is published')->toBe([])
            ->and(\file_exists($output))->toBeFalse();

        $store->cleanup();
    }

    #[Test]
    public function unsafeNamesSymlinksAndLimitsFailLoudly(): void
    {
        $root = $this->tempDirectory->subdirectory('safety');
        $configuration = new ArtifactConfiguration(
            $root,
            maxAttachmentsPerTest: 1,
            maxAttachmentBytes: 4,
            maxTestBytes: 4,
            maxRunAttachments: 1,
            maxRunBytes: 4,
        );
        $store = ArtifactStore::open($configuration, $root, 'run-3');
        $id = new TestId('Example\EvidenceTest', 'limits');
        $budget = new TestArtifactBudget();
        $attachments = $store->forAttempt($id, 1, $budget);

        Expect::that(static fn() => $attachments->text('../secret', 'x'))->because('unsafe names symlinks and limits fail loudly')
            ->toThrow(AttachmentError::class);
        Expect::that(static fn() => $attachments->bytes('large.bin', '12345'))->because('unsafe names symlinks and limits fail loudly')
            ->toThrow(AttachmentError::class);

        $attachments->text('one.txt', '1234');

        Expect::that(static fn() => $attachments->text('two.txt', 'x'))->because('unsafe names symlinks and limits fail loudly')
            ->toThrow(AttachmentError::class);
        $retry = $store->forAttempt($id, 2, $budget);
        Expect::that(static fn() => $retry->text('retry.txt', 'x'))->because('unsafe names symlinks and limits fail loudly')
            ->toThrow(AttachmentError::class);
        $runLimited = $store->forAttempt(new TestId('Example\EvidenceTest', 'run-limit'), 1, new TestArtifactBudget());
        Expect::that(static fn() => $runLimited->text('other.txt', 'x'))->because('unsafe names symlinks and limits fail loudly')
            ->toThrow(AttachmentError::class);

        $source = $this->tempDirectory->path() . '/target.txt';
        $link = $this->tempDirectory->path() . '/link.txt';
        \file_put_contents($source, 'data');
        \symlink($source, $link);
        $other = $store->forAttempt(new TestId('Example\EvidenceTest', 'symlink'), 1, new TestArtifactBudget());

        Expect::that(static fn() => $other->file('link.txt', $link))->because('unsafe names symlinks and limits fail loudly')
            ->toThrow(AttachmentError::class);

        $store->cleanup();
    }

    #[Test]
    public function completedEvidenceCanBeRecoveredAfterAWorkerCrash(): void
    {
        $root = $this->tempDirectory->subdirectory('recovery');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-4');
        $id = new TestId('Example\EvidenceTest', 'crashes');
        $attachments = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attachments->text('last-response.txt', 'completed before crash');

        $recovered = $store->recover(new TestResult($id, Outcome::Errored, 0.0, 0));

        Expect::that($recovered->attachments)->because('completed evidence can be recovered after a worker crash')->toHaveCount(1)
            ->and($recovered->attachments[0]->name)->toBe('last-response.txt')
            ->and(\is_file($recovered->attachments[0]->path))->toBeTrue();

        $store->cleanup();
    }

    #[Test]
    public function crashRecoveryRestoresTheLatestAttemptWithoutAnAttachment(): void
    {
        $root = $this->tempDirectory->subdirectory('attempt-recovery');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-5');
        $id = new TestId('Example\EvidenceTest', 'crashesOnRetry');
        $budget = new TestArtifactBudget();
        $first = $store->forAttempt($id, 1, $budget);
        $first->text('first-attempt.txt', 'failed before retry');
        $first->seal();
        $store->forAttempt($id, 2, $budget);

        $recovered = $store->recover(new TestResult($id, Outcome::Errored, 0.0, 0));

        Expect::that($recovered->attempts)->because('crash recovery restores the latest attempt without an attachment')->toBe(2)
            ->and($recovered->attachments)->toHaveCount(1)
            ->and($recovered->attachments[0]->attempt)->toBe(1);

        $store->cleanup();
    }

    private function absolute(string $workingDirectory, string $publishedPath): string
    {
        return \str_starts_with($publishedPath, '/')
            ? $publishedPath
            : \rtrim($workingDirectory, '/') . '/' . $publishedPath;
    }
}
