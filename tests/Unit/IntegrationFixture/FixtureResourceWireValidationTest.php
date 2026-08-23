<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\IntegrationFixture;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\IntegrationFixture\FixtureResource;
use Greenlight\Internal\Wire\InvalidWirePayload;

final readonly class FixtureResourceWireValidationTest
{
    /**
     * @param array<string, mixed> $payload
     */
    #[Test]
    #[DataSet('invalidWirePayloads')]
    public function invalidResourcePayloadsRemainWireCommunicationFaileds(
        array $payload,
        string $message,
    ): void {
        Expect::that(static fn(): FixtureResource => FixtureResource::fromWire($payload))
            ->because('invalid fixture resources MUST remain protocol errors at the wire boundary')
            ->toThrow(InvalidWirePayload::class, message: $message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidWirePayloads(): iterable
    {
        $secretShapeMessage = 'Wire payload key "secrets" must be a map of non-empty string keys to strings, got array.';
        $resourceMessage = 'Wire payload key "resource" must be JSON-safe values and string secrets, got array.';

        yield 'empty secret key' => [[
            'values' => [],
            'secrets' => ['' => 'secret'],
        ], $secretShapeMessage];
        yield 'non-string secret' => [[
            'values' => [],
            'secrets' => ['token' => 123],
        ], $secretShapeMessage];
        yield 'invalid UTF-8 ordinary value' => [[
            'values' => ['text' => "\xB1\x31"],
            'secrets' => [],
        ], $resourceMessage];
        yield 'invalid UTF-8 secret' => [[
            'values' => [],
            'secrets' => ['token' => "\xB1\x31"],
        ], $resourceMessage];
        yield 'non-finite ordinary value' => [[
            'values' => ['ratio' => \INF],
            'secrets' => [],
        ], $resourceMessage];
    }
}
