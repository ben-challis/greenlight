<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final class ArtifactConfigurationWireTest
{
    #[Test]
    public function everyArtifactLimitSurvivesAJsonWireRoundTrip(): void
    {
        $configuration = new ArtifactConfiguration(
            directory: 'custom/artifacts',
            maxAttachmentsPerTest: 11,
            maxAttachmentBytes: 22,
            maxTestBytes: 33,
            maxRunAttachments: 44,
            maxRunBytes: 55,
        );

        $restored = ArtifactConfiguration::fromWire(
            JsonWire::roundTrip($configuration->toWire()),
        );

        Expect::that($restored)
            ->because('workers MUST receive every configured artifact safety limit')
            ->toEqual($configuration);
    }

    #[Test]
    #[DataSet('nonpositiveLimits')]
    public function nonpositiveArtifactLimitsNormalizeToOne(string $field, int $value): void
    {
        $payload = new ArtifactConfiguration()->toWire();
        $payload[$field] = $value;

        $restored = ArtifactConfiguration::fromWire(JsonWire::roundTrip($payload));

        Expect::that($restored->toWire()[$field])
            ->because('artifact safety limits MUST remain positive across the worker wire')
            ->toBe(1);
    }

    /**
     * @return iterable<string, array{non-empty-string, int}>
     */
    public static function nonpositiveLimits(): iterable
    {
        foreach ([
            'max attachments per test' => 'maxAttachmentsPerTest',
            'max attachment bytes' => 'maxAttachmentBytes',
            'max test bytes' => 'maxTestBytes',
            'max run attachments' => 'maxRunAttachments',
            'max run bytes' => 'maxRunBytes',
        ] as $label => $field) {
            yield $label . ': zero' => [$field, 0];
            yield $label . ': negative' => [$field, -1];
        }
    }
}
