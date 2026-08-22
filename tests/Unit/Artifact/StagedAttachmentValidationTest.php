<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\AttachmentKind;
use Greenlight\Artifact\AttachmentRetention;
use Greenlight\Artifact\StagedAttachment;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Wire\InvalidWirePayload;

final class StagedAttachmentValidationTest
{
    #[Test]
    public function rejectsAnEmptyStorageKeyAtConstruction(): void
    {
        Expect::that(static fn(): StagedAttachment => new StagedAttachment(
            'artifact',
            AttachmentKind::Text,
            'text/plain',
            1,
            \str_repeat('a', 64),
            1,
            'artifact.txt',
        ))
            ->because('a staged attachment MUST identify its private storage coordinate')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Attachment storage key is invalid.',
            );
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[Test]
    #[DataSet('invalidStorageKeyPayloads')]
    public function wireDecodingRequiresAStorageKey(array $payload): void
    {
        Expect::that(static fn(): StagedAttachment => StagedAttachment::fromWire($payload))
            ->because('a staged attachment wire payload MUST contain its private storage coordinate')
            ->toThrow(InvalidWirePayload::class);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidStorageKeyPayloads(): iterable
    {
        $payload = self::wirePayload();

        unset($payload['storageKey']);
        yield 'missing' => [$payload];

        yield 'empty' => [[...self::wirePayload(), 'storageKey' => '']];
    }

    #[Test]
    #[DataSet('nonPositiveAttempts')]
    public function wireDecodingNormalizesNumericBounds(int $attempt): void
    {
        $staged = StagedAttachment::fromWire([
            ...self::wirePayload(),
            'sizeBytes' => -1,
            'attempt' => $attempt,
        ]);

        Expect::that($staged->sizeBytes)
            ->because('staged attachment wire decoding MUST normalize a negative size to zero')
            ->toBe(0);
        Expect::that($staged->attempt)
            ->because('staged attachment wire decoding MUST normalize a nonpositive attempt to one')
            ->toBe(1);
        Expect::that($staged->storageKey)
            ->because('staged attachment wire decoding MUST preserve the storage key')
            ->toBe('test/attempt-1/01-artifact.txt');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonPositiveAttempts(): iterable
    {
        yield 'zero attempt' => [0];
        yield 'negative attempt' => [-1];
    }

    /**
     * @return array<string, mixed>
     */
    private static function wirePayload(): array
    {
        return [
            'name' => 'artifact',
            'kind' => AttachmentKind::Text->value,
            'mediaType' => 'text/plain',
            'sizeBytes' => 1,
            'sha256' => \str_repeat('a', 64),
            'attempt' => 1,
            'path' => 'artifact.txt',
            'retention' => AttachmentRetention::Always->value,
            'storageKey' => 'test/attempt-1/01-artifact.txt',
        ];
    }
}
