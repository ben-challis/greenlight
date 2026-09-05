<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Command;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Command\TestManifest;
use Greenlight\Condition\EnvironmentVariableEquals;
use Greenlight\Config\SuiteConfiguration;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Test\ExecutionPolicy;
use Greenlight\Test\RetryPolicy;
use Greenlight\Test\SchedulingPolicy;
use Greenlight\Test\SkipPolicy;
use Greenlight\Test\TestDefinition;

final class TestManifestTest
{
    #[Test]
    public function numericSuiteNamesRemainStringsInTheManifest(): void
    {
        $document = TestManifest::document(
            new ExecutionPlan([new PlanEntry(new TestDefinition(self::class, __FUNCTION__))]),
            [
                new SuiteConfiguration('123', ['tests/Unit'], []),
                new SuiteConfiguration('0', ['tests/Unit'], []),
                new SuiteConfiguration('01', ['tests/Unit'], []),
                new SuiteConfiguration('-1', ['tests/Unit'], []),
            ],
            null,
            \dirname(__DIR__, 4),
        );

        Expect::that(\json_encode($document, \JSON_THROW_ON_ERROR))
            ->toContain('"suites":["-1","0","01","123"]');
    }

    #[Test]
    public function documentKeepsThePublicContractSmallAndDeterministic(): void
    {
        $root = \dirname(__DIR__, 4);
        $definition = new TestDefinition(
            self::class,
            __FUNCTION__,
            ['zeta', 'alpha'],
            new SkipPolicy('private skip reason', EnvironmentVariableEquals::class, ['PRIVATE_NAME', 'private value']),
            new RetryPolicy(2, \RuntimeException::class),
            execution: new ExecutionPolicy(1.25, false, true),
            scheduling: new SchedulingPolicy(true, ['zeta-resource', 'alpha-resource']),
        );
        $document = TestManifest::document(
            new ExecutionPlan([new PlanEntry($definition, 'labeled row')], 17),
            [
                new SuiteConfiguration('unit', ['tests/Unit'], []),
                new SuiteConfiguration('all', ['tests'], []),
            ],
            [2, 3],
            $root,
        );
        $method = new \ReflectionMethod(self::class, __FUNCTION__);

        Expect::that($document)->toBe([
            'version' => 1,
            'order' => [
                'tests' => 'plan',
                'completion' => 'not-applicable',
                'seed' => 17,
            ],
            'shard' => [
                'index' => 2,
                'count' => 3,
            ],
            'tests' => [[
                'id' => self::class . '::' . __FUNCTION__ . '[labeled row]',
                'class' => self::class,
                'method' => __FUNCTION__,
                'dataSetKey' => 'labeled row',
                'source' => [
                    'file' => __FILE__,
                    'line' => $method->getStartLine(),
                ],
                'groups' => ['alpha', 'zeta'],
                'suites' => ['all', 'unit'],
                'skip' => [
                    'present' => true,
                    'condition' => EnvironmentVariableEquals::class,
                ],
                'retry' => [
                    'additionalAttempts' => 2,
                    'onlyOn' => \RuntimeException::class,
                ],
                'timeoutSeconds' => 1.25,
                'captureOutput' => false,
                'noExpectations' => true,
                'resources' => ['alpha-resource', 'zeta-resource'],
                'isolated' => true,
                'allowParallel' => false,
            ]],
        ]);

        $json = \json_encode($document, \JSON_THROW_ON_ERROR);
        Expect::that($json)
            ->because('the public manifest MUST omit skip reasons and condition arguments')
            ->not()
            ->toContain('private skip reason')
            ->not()
            ->toContain('PRIVATE_NAME')
            ->not()
            ->toContain('private value');
    }
}
