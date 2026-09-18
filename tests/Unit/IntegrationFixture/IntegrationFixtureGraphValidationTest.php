<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\IntegrationFixture;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\IntegrationFixture\IntegrationFixtureContext;
use Greenlight\IntegrationFixture\IntegrationFixtureDefinition;
use Greenlight\IntegrationFixture\IntegrationFixtureError;
use Greenlight\IntegrationFixture\IntegrationFixtureManager;

final readonly class IntegrationFixtureGraphValidationTest
{
    /** @param list<array{string, list<string>}> $invalidGraph */
    #[Test]
    #[DataSet('invalidGraphs')]
    public function aLaterInvalidDefinitionPreventsEveryProvisionerFromRunning(array $invalidGraph, string $message): void
    {
        $trace = [];
        $provision = static function (IntegrationFixtureContext $context) use (&$trace): void {
            $trace[] = 'acquired';
            $context->defer(static function () use (&$trace): void {
                $trace[] = 'released';
            });
        };
        $definitions = [new IntegrationFixtureDefinition('ready', $provision)];

        foreach ($invalidGraph as [$id, $dependencies]) {
            $definitions[] = new IntegrationFixtureDefinition($id, $provision, $dependencies);
        }

        Expect::that(static fn() => IntegrationFixtureManager::provision(
            $definitions,
            'invalid-graph',
            1,
            1,
            null,
        ))->toThrow(IntegrationFixtureError::class, message: $message);

        Expect::that($trace)
            ->because('the complete graph must be valid before any fixture acquires resources')
            ->toBe([]);
    }

    /** @return iterable<string, array{list<array{string, list<string>}>, string}> */
    public static function invalidGraphs(): iterable
    {
        yield 'missing dependency after a valid fixture' => [
            [['database', ['network']]],
            'Integration fixture "database" depends on missing fixture "network".',
        ];
        yield 'cycle after a valid fixture' => [
            [['database', ['network']], ['network', ['database']]],
            'Integration fixture dependency cycle: database -> network -> database.',
        ];
        yield 'duplicate after a valid fixture' => [
            [['database', []], ['database', []]],
            'Integration fixture "database" is declared more than once.',
        ];
    }
}
