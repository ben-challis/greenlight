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

        $retained = $attachments->finalize(problematic: true);
        $published = $store->publish(new TestResult(
            new TestId('Example\EvidenceTest', 'fails'),
            Outcome::Failed,
            0.1,
            0,
            attachments: $retained,
        ));

        Expect::that($published->attachments)->toHaveCount(5)
            ->and($published->attachments[1]->name)->toBe('response.txt')
            ->and($published->attachments[3]->name)->toBe('response.txt')
            ->and($published->attachments[3]->path)->toContain('response-2.txt');

        foreach ($published->attachments as $attachment) {
            $path = $this->absolute($root, $attachment->path);
            Expect::that(\is_file($path))->toBeTrue()
                ->and(\hash_file('sha256', $path))->toBe($attachment->sha256)
                ->and($attachment->storageKey())->toBeNull();
        }

        Expect::that((string) \file_get_contents($this->absolute($root, $published->attachments[0]->path)))
            ->toBe("{\"ok\":true}\n");
        Expect::that((string) \file_get_contents($this->absolute($root, $published->attachments[4]->path)))
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

        $retained = $attachments->finalize(problematic: false);

        Expect::that($retained)->toHaveCount(1)
            ->and($retained[0]->name)->toBe('kept.txt');

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

        Expect::that(static fn() => $attachments->text('../secret', 'x'))
            ->toThrow(AttachmentError::class);
        Expect::that(static fn() => $attachments->bytes('large.bin', '12345'))
            ->toThrow(AttachmentError::class);

        $attachments->text('one.txt', '1234');

        Expect::that(static fn() => $attachments->text('two.txt', 'x'))
            ->toThrow(AttachmentError::class);
        $retry = $store->forAttempt($id, 2, $budget);
        Expect::that(static fn() => $retry->text('retry.txt', 'x'))
            ->toThrow(AttachmentError::class);
        $runLimited = $store->forAttempt(new TestId('Example\EvidenceTest', 'run-limit'), 1, new TestArtifactBudget());
        Expect::that(static fn() => $runLimited->text('other.txt', 'x'))
            ->toThrow(AttachmentError::class);

        $source = $this->tempDirectory->path() . '/target.txt';
        $link = $this->tempDirectory->path() . '/link.txt';
        \file_put_contents($source, 'data');
        \symlink($source, $link);
        $other = $store->forAttempt(new TestId('Example\EvidenceTest', 'symlink'), 1, new TestArtifactBudget());

        Expect::that(static fn() => $other->file('link.txt', $link))
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

        Expect::that($recovered->attachments)->toHaveCount(1)
            ->and($recovered->attachments[0]->name)->toBe('last-response.txt')
            ->and(\is_file($recovered->attachments[0]->path))->toBeTrue();

        $store->cleanup();
    }

    private function absolute(string $workingDirectory, string $publishedPath): string
    {
        return \str_starts_with($publishedPath, '/')
            ? $publishedPath
            : \rtrim($workingDirectory, '/') . '/' . $publishedPath;
    }
}
