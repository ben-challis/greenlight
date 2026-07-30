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
    /**
     * @param 'coverageDriver'|'configFile' $field
     */
    #[Test]
    #[DataSet('optionalStrings')]
    public function optionalStringsAreNormalizedFromTheWire(
        string $field,
        string $wireValue,
        ?string $expected,
    ): void {
        $actual = match ($field) {
            'coverageDriver' => $this->coverageDriver($wireValue),
            'configFile' => $this->configFile($wireValue),
        };

        Expect::that($actual)
            ->because('optional protocol strings MUST be null or non-empty')
            ->toBe($expected);
    }

    private function coverageDriver(string $wireValue): ?string
    {
        $payload = new Assign(new ExecutionPlan([]))->toWire();
        $payload['coverageDriver'] = $wireValue;

        return Assign::fromWire($payload)->coverageDriver;
    }

    private function configFile(string $wireValue): ?string
    {
        $payload = new Bootstrap(1, null, IntegrationResources::empty())->toWire();
        $payload['configFile'] = $wireValue;

        return Bootstrap::fromWire($payload)->configFile;
    }

    /**
     * @return iterable<string, array{'coverageDriver'|'configFile', string, string|null}>
     */
    public static function optionalStrings(): iterable
    {
        yield 'empty coverage driver' => ['coverageDriver', '', null];
        yield 'non-empty coverage driver' => ['coverageDriver', 'xdebug', 'xdebug'];
        yield 'empty config file' => ['configFile', '', null];
        yield 'non-empty config file' => ['configFile', '/app/greenlight.php', '/app/greenlight.php'];
    }
}
