<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Artifact\AttachmentRetention;
use Greenlight\Core\Artifact\StagedAttachment;
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
            ->toBe(AttachmentRetention::Always->value)
            ->and($decoded)
            ->toEqual($attachment);
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
            ->toBe(AttachmentRetention::OnFailure)
            ->and($staged->retention)
            ->toBe(AttachmentRetention::OnFailure);
    }

    #[Test]
    public function wireDecodingNormalizesNumericBounds(): void
    {
        $attachment = Attachment::fromWire([
            'name' => 'response.json',
            'kind' => AttachmentKind::Value->value,
            'mediaType' => 'application/json',
            'sizeBytes' => -1,
            'sha256' => \str_repeat('a', 64),
            'attempt' => 0,
            'path' => 'build/artifacts/response.json',
            'retention' => AttachmentRetention::Always->value,
        ]);

        Expect::that([$attachment->sizeBytes, $attachment->attempt])
            ->because('attachment wire decoding MUST preserve its numeric bounds')
            ->toBe([0, 1]);
    }
}
