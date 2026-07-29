<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\Messages\Assign;

final class AssignOptionalStringWireTest
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
        $payload = new Assign(new ExecutionPlan([]))->toWire();
        $payload[$field] = $wireValue;

        $assign = Assign::fromWire($payload);
        $actual = match ($field) {
            'coverageDriver' => $assign->coverageDriver,
            'configFile' => $assign->configFile,
        };

        Expect::that($actual)
            ->because('optional assignment strings MUST be null or non-empty')
            ->toBe($expected);
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
