<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Expect\Expect;

final readonly class ArtifactConfigurationWireBoundsTest
{
    #[Test]
    #[DataSet('nonPositiveLimits')]
    public function nonPositiveWireLimitsNormalizeToOne(string $field, int $invalid): void
    {
        $payload = new ArtifactConfiguration()->toWire();
        $payload[$field] = $invalid;
        $configuration = ArtifactConfiguration::fromWire($payload);
        $actual = match ($field) {
            'maxAttachmentsPerTest' => $configuration->maxAttachmentsPerTest,
            'maxAttachmentBytes' => $configuration->maxAttachmentBytes,
            'maxTestBytes' => $configuration->maxTestBytes,
            'maxRunAttachments' => $configuration->maxRunAttachments,
            'maxRunBytes' => $configuration->maxRunBytes,
            default => throw new \LogicException(\sprintf('Unknown artifact limit field: %s', $field)),
        };

        Expect::that($actual)
            ->because(\sprintf('%s MUST remain positive after wire decoding', $field))
            ->toBe(1);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function nonPositiveLimits(): iterable
    {
        yield 'zero max attachments per test' => ['maxAttachmentsPerTest', 0];

        yield 'negative max attachments per test' => ['maxAttachmentsPerTest', -1];

        yield 'zero max attachment bytes' => ['maxAttachmentBytes', 0];

        yield 'negative max attachment bytes' => ['maxAttachmentBytes', -1];

        yield 'zero max test bytes' => ['maxTestBytes', 0];

        yield 'negative max test bytes' => ['maxTestBytes', -1];

        yield 'zero max run attachments' => ['maxRunAttachments', 0];

        yield 'negative max run attachments' => ['maxRunAttachments', -1];

        yield 'zero max run bytes' => ['maxRunBytes', 0];

        yield 'negative max run bytes' => ['maxRunBytes', -1];
    }
}
