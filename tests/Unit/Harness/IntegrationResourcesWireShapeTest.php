<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Expect\Expect;
use Greenlight\Harness\IntegrationResources;

final readonly class IntegrationResourcesWireShapeTest
{
    /**
     * @param array<string, mixed> $fixtures
     */
    #[Test]
    #[DataSet('invalidFixtureEntries')]
    public function invalidFixtureEntriesRemainWireErrors(array $fixtures, string $message): void
    {
        Expect::that(static fn(): IntegrationResources => IntegrationResources::fromWire([
            'fixtures' => $fixtures,
        ]))
            ->because('invalid integration resource entries MUST remain protocol errors')
            ->toThrow(InvalidWirePayload::class, message: $message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidFixtureEntries(): iterable
    {
        yield 'empty fixture ID' => [
            ['' => ['values' => [], 'secrets' => []]],
            'Wire payload key "fixtures" must be a map of fixture resource payloads, got array.',
        ];
        yield 'non-array resource payload' => [
            ['database' => 'invalid'],
            'Wire payload key "fixtures" must be a map of fixture resource payloads, got string.',
        ];
    }
}
