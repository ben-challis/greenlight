<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\Attachment;
use Greenlight\Artifact\AttachmentKind;
use Greenlight\Artifact\AttachmentRetention;
use Greenlight\Artifact\StagedAttachment;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class AttachmentWireTest
{
    #[Test]
    public function explicitRetentionSurvivesTheWireRoundTrip(): void
    {
        $attachment = new Attachment(
            name: 'response.json',
            kind: AttachmentKind::Value,
            mediaType: 'application/json',
            sizeBytes: 2,
            sha256: \str_repeat('a', 64),
            attempt: 1,
            path: 'build/artifacts/response.json',
            retention: AttachmentRetention::Always,
        );

        $payload = $attachment->toWire();
        $decoded = Attachment::fromWire($payload);

        Expect::that($payload['retention'])
            ->because('explicit attachment retention survives the wire round-trip')
            ->toBe(AttachmentRetention::Always->value);
        Expect::that($decoded)
            ->toEqual($attachment);
    }

    #[Test]
    public function explicitStagedRetentionSurvivesWireDecoding(): void
    {
        $staged = StagedAttachment::fromWire([
            'name' => 'response.json',
            'kind' => AttachmentKind::Value->value,
            'mediaType' => 'application/json',
            'sizeBytes' => 2,
            'sha256' => \str_repeat('a', 64),
            'attempt' => 1,
            'path' => 'build/artifacts/response.json',
            'retention' => AttachmentRetention::Always->value,
            'storageKey' => 'attempt/response.json',
        ]);

        Expect::that($staged->retention)
            ->because('explicit staged attachment retention MUST survive wire decoding')
            ->toBe(AttachmentRetention::Always);
    }

    #[Test]
    public function payloadsWithoutRetentionUseTheBackwardCompatibleDefault(): void
    {
        $payload = [
            'name' => 'response.json',
            'kind' => 'value',
            'mediaType' => 'application/json',
            'sizeBytes' => 2,
            'sha256' => \str_repeat('a', 64),
            'attempt' => 1,
            'path' => 'build/artifacts/response.json',
        ];

        $attachment = Attachment::fromWire($payload);
        $staged = StagedAttachment::fromWire([
            ...$payload,
            'storageKey' => 'attempt/response.json',
        ]);

        Expect::that($attachment->retention)
            ->because('older attachment payloads use on-failure retention')
            ->toBe(AttachmentRetention::OnFailure);
        Expect::that($staged->retention)
            ->toBe(AttachmentRetention::OnFailure);
    }

    #[Test]
    #[DataSet('nonPositiveAttempts')]
    public function wireDecodingNormalizesNumericBounds(int $attempt): void
    {
        $attachment = Attachment::fromWire([
            'name' => 'response.json',
            'kind' => AttachmentKind::Value->value,
            'mediaType' => 'application/json',
            'sizeBytes' => -1,
            'sha256' => \str_repeat('a', 64),
            'attempt' => $attempt,
            'path' => 'build/artifacts/response.json',
            'retention' => AttachmentRetention::Always->value,
        ]);

        Expect::that($attachment->sizeBytes)
            ->because('attachment wire decoding MUST normalize a negative size to zero')
            ->toBe(0);
        Expect::that($attachment->attempt)
            ->because('attachment wire decoding MUST normalize a nonpositive attempt to one')
            ->toBe(1);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonPositiveAttempts(): iterable
    {
        yield 'zero attempt' => [0];
        yield 'negative attempt' => [-1];
    }
}
