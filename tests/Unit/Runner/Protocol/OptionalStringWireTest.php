<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Expect\Expect;
use Greenlight\Harness\IntegrationResources;
use Greenlight\Runner\Protocol\Messages\Assign;
use Greenlight\Runner\Protocol\Messages\Bootstrap;

final class OptionalStringWireTest
{
    #[Test]
    #[DataSet('optionalStringValues')]
    public function assignmentCoverageDriversAreNormalizedFromTheWire(
        string $wireValue,
        ?string $expected,
    ): void {
        $payload = new Assign(new ExecutionPlan([]))->toWire();
        $payload['coverageDriver'] = $wireValue;

        $assign = Assign::fromWire($payload);

        Expect::that($assign->coverageDriver)
            ->because('optional assignment coverage drivers MUST be null or non-empty')
            ->toBe($expected);
    }

    #[Test]
    #[DataSet('optionalStringValues')]
    public function bootstrapConfigFilesAreNormalizedFromTheWire(
        string $wireValue,
        ?string $expected,
    ): void {
        $payload = new Bootstrap(1, null, new IntegrationResources([]))->toWire();
        $payload['configFile'] = $wireValue;

        $bootstrap = Bootstrap::fromWire($payload);

        Expect::that($bootstrap->configFile)
            ->because('optional bootstrap configuration files MUST be null or non-empty')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function optionalStringValues(): iterable
    {
        yield 'empty string' => ['', null];
        yield 'zero string' => ['0', '0'];
        yield 'non-empty string' => ['value', 'value'];
    }
}
