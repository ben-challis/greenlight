<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Artifact\ArtifactSession;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\TestId;

final readonly class ArtifactQuotaTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function aQuotaSymlinkCannotRedirectSharedAccounting(): void
    {
        $root = $this->tempDirectory->subdirectory('quota-symlink');
        $staging = $root . '/staging';
        $outside = $root . '/outside-quota';
        \mkdir($staging);
        \file_put_contents($outside, 'untouched');
        \symlink($outside, $staging . '/.quota');
        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-1'),
            new ArtifactConfiguration($root . '/published'),
        );
        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'recordsEvidence'),
            1,
            new TestArtifactBudget(),
        );

        Expect::that(static function () use ($attachments): void {
            $attachments->text('evidence.txt', 'body');
        })
            ->because('shared quota accounting MUST NOT follow symbolic links')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachment quota path is unsafe.',
            );
        Expect::that((string) \file_get_contents($outside))
            ->because('a rejected quota path MUST NOT change its target')
            ->toBe('untouched');
    }

    #[Test]
    #[DataSet('invalidQuotaMetadata')]
    public function corruptRunQuotaMetadataFailsBeforeStaging(string $metadata): void
    {
        $root = $this->tempDirectory->subdirectory('corrupt-run-quota');
        $staging = $root . '/staging';
        \mkdir($staging);
        \file_put_contents($staging . '/.quota', $metadata);
        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-1'),
            new ArtifactConfiguration($root . '/published'),
        );
        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'recordsEvidence'),
            1,
            new TestArtifactBudget(),
        );

        Expect::that(static function () use ($attachments): void {
            $attachments->text('evidence.txt', 'body');
        })
            ->because('corrupt shared quota metadata MUST stop attachment staging')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachment quota metadata is corrupt.',
            );
        Expect::that(\glob($staging . '/*/attempt-*'))
            ->because('a rejected quota reservation MUST NOT leave staging data')
            ->toBe([]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidQuotaMetadata(): iterable
    {
        yield 'invalid syntax' => ['not quota metadata'];
        yield 'attachment count overflows an integer' => [\str_repeat('9', 30) . ' 0'];
        yield 'byte count overflows an integer' => ['0 ' . \str_repeat('9', 30)];
    }

    #[Test]
    #[DataSet('maximumQuotaValues')]
    public function maximumQuotaValuesCannotOverflowAccounting(string $metadata, string $message): void
    {
        $root = $this->tempDirectory->subdirectory('maximum-run-quota');
        $staging = $root . '/staging';
        \mkdir($staging);
        $quota = $staging . '/.quota';
        \file_put_contents($quota, $metadata);
        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-1'),
            new ArtifactConfiguration(
                $root . '/published',
                maxRunAttachments: \PHP_INT_MAX,
                maxRunBytes: \PHP_INT_MAX,
            ),
        );
        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'recordsEvidence'),
            1,
            new TestArtifactBudget(),
        );

        Expect::that(static function () use ($attachments): void {
            $attachments->text('evidence.txt', 'body');
        })
            ->because('quota accounting MUST reject additions that exceed an integer limit')
            ->toThrow(AttachmentError::class, message: $message);
        Expect::that((string) \file_get_contents($quota))
            ->because('a rejected quota reservation MUST keep its previous accounting')
            ->toBe($metadata);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function maximumQuotaValues(): iterable
    {
        yield 'attachment count' => [
            \PHP_INT_MAX . ' 0',
            \sprintf('This run has reached the limit of %d attachments.', \PHP_INT_MAX),
        ];
        yield 'byte count' => [
            '0 ' . \PHP_INT_MAX,
            \sprintf('Attachments for this run exceed the limit of %d bytes.', \PHP_INT_MAX),
        ];
    }
}
