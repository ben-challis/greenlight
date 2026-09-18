<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\IntegrationFixture;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\IntegrationFixture\FixtureResource;
use Greenlight\IntegrationFixture\IntegrationFixtureContext;
use Greenlight\IntegrationFixture\IntegrationFixtureDefinition;
use Greenlight\IntegrationFixture\IntegrationFixtureError;
use Greenlight\IntegrationFixture\IntegrationFixtureManager;

final class IntegrationFixtureManagerTest
{
    #[Test]
    public function dependenciesProvisionFirstAndCleanupRunsInReverseAcquisitionOrder(): void
    {
        $trace = [];
        $observedContext = null;

        $session = IntegrationFixtureManager::provision(
            [
                new IntegrationFixtureDefinition(
                    'database',
                    function (IntegrationFixtureContext $context) use (&$trace): void {
                        $trace[] = 'database:start';
                        $host = $context->dependency('network')->string('host');
                        $context->defer(static function () use (&$trace): void {
                            $trace[] = 'database:stop';
                        });
                        $context->expose(
                            FixtureResource::from(['host' => $host]),
                            [
                                1 => FixtureResource::from(['database' => 'test_1']),
                                2 => FixtureResource::from(['database' => 'test_2']),
                            ],
                        );
                    },
                    ['network'],
                ),
                new IntegrationFixtureDefinition(
                    'network',
                    function (IntegrationFixtureContext $context) use (&$trace, &$observedContext): void {
                        $trace[] = 'network:start';
                        $observedContext = [
                            $context->runId(),
                            $context->configuredWorkers(),
                            $context->channels(),
                            $context->shard(),
                        ];
                        $context->defer(static function () use (&$trace): void {
                            $trace[] = 'network:stop';
                        });
                        $context->expose(FixtureResource::from(['host' => '127.0.0.1']));
                    },
                ),
            ],
            'run-1',
            4,
            2,
            [2, 3],
        );

        $channelOne = $session->forChannel(1)->fixture('database');
        $channelTwo = $session->forChannel(2)->fixture('database');

        Expect::value($trace)->toBe(['network:start', 'database:start']);
        Expect::value($observedContext)->toBe(['run-1', 4, [1, 2], [2, 3]]);
        Expect::value($channelOne->string('database'))->toBe('test_1');
        Expect::value($channelTwo->string('database'))->toBe('test_2');
        Expect::value($session->close())->toBe([]);
        Expect::value($trace)->toBe([
            'network:start',
            'database:start',
            'database:stop',
            'network:stop',
        ]);
        Expect::value($session->close())->toBe([]);
    }

    #[Test]
    public function fixturesThatDoNotExposeResourcesRemainAvailableAsEmptyResources(): void
    {
        $session = IntegrationFixtureManager::provision(
            [new IntegrationFixtureDefinition('database', static function (): void {})],
            'run-2',
            2,
            2,
            null,
        );

        Expect::value($session->forChannel(1)->fixture('database')->toWire())
            ->because('every provisioned fixture MUST remain available even when it exposes no resources')
            ->toBe([
                'values' => [],
                'secrets' => [],
            ]);
    }

    #[Test]
    public function partialProvisioningCleansEverythingAlreadyAcquired(): void
    {
        $trace = [];
        $definitions = [
            new IntegrationFixtureDefinition(
                'first',
                function (IntegrationFixtureContext $context) use (&$trace): void {
                    $trace[] = 'first:start';
                    $context->defer(static function () use (&$trace): void {
                        $trace[] = 'first:stop';
                    });
                },
            ),
            new IntegrationFixtureDefinition(
                'second',
                function (IntegrationFixtureContext $context) use (&$trace): void {
                    $trace[] = 'second:start';
                    $context->defer(static function () use (&$trace): void {
                        $trace[] = 'second:stop';
                    });

                    throw new \RuntimeException('could not start');
                },
                ['first'],
            ),
        ];

        Expect::calling(fn() => IntegrationFixtureManager::provision(
            $definitions,
            'run-2',
            2,
            2,
            null,
        ))->toThrow(IntegrationFixtureError::class, matching: '/second.*could not start/');

        Expect::value($trace)->toBe([
            'first:start',
            'second:start',
            'second:stop',
            'first:stop',
        ]);
    }

    #[Test]
    public function provisioningFailuresRetainCleanupFailuresWithoutReplacingThePrimaryCause(): void
    {
        $provisioningFailure = new \RuntimeException('database start failed');
        $cleanupFailure = new \LogicException('network stop failed');
        $definitions = [
            new IntegrationFixtureDefinition(
                'network',
                static function (IntegrationFixtureContext $context) use ($cleanupFailure): void {
                    $context->defer(static function () use ($cleanupFailure): void {
                        throw $cleanupFailure;
                    });
                },
            ),
            new IntegrationFixtureDefinition(
                'database',
                static function () use ($provisioningFailure): void {
                    throw $provisioningFailure;
                },
                ['network'],
            ),
        ];

        Expect::calling(static fn() => IntegrationFixtureManager::provision(
            $definitions,
            'run-3',
            1,
            1,
            null,
        ))
            ->because('provisioning diagnostics MUST include cleanup failures without replacing the primary failure')
            ->toThrow(static function (IntegrationFixtureError $error) use ($provisioningFailure): void {
                Expect::value($error->getMessage())->toBe(
                    "Integration fixture \"database\" failed to provision: database start failed.\n"
                    . 'Additionally, cleanup for integration fixture "network" failed: network stop failed.',
                );
                Expect::value($error->getPrevious())
                    ->because('the provisioning failure MUST remain the previous exception')
                    ->toBe($provisioningFailure);
            });
    }

    #[Test]
    public function missingDependenciesAndCyclesFailBeforeProvisioning(): void
    {
        $missing = [
            new IntegrationFixtureDefinition('database', static function (): void {}, ['network']),
        ];

        Expect::calling(fn() => IntegrationFixtureManager::provision(
            $missing,
            'run-3',
            1,
            1,
            null,
        ))->toThrow(IntegrationFixtureError::class, matching: '/missing fixture "network"/');

        $cycle = [
            new IntegrationFixtureDefinition('alpha', static function (): void {}, ['bravo']),
            new IntegrationFixtureDefinition('bravo', static function (): void {}, ['alpha']),
        ];

        Expect::calling(fn() => IntegrationFixtureManager::provision(
            $cycle,
            'run-4',
            1,
            1,
            null,
        ))->toThrow(IntegrationFixtureError::class, matching: '/alpha -> bravo -> alpha/');
    }

    #[Test]
    public function cycleDiagnosticsExcludeAcyclicDependencyPrefixes(): void
    {
        $definitions = [
            new IntegrationFixtureDefinition('entry', static function (): void {}, ['alpha']),
            new IntegrationFixtureDefinition('alpha', static function (): void {}, ['bravo']),
            new IntegrationFixtureDefinition('bravo', static function (): void {}, ['alpha']),
        ];

        Expect::calling(fn() => IntegrationFixtureManager::provision(
            $definitions,
            'run-cycle-prefix',
            1,
            1,
            null,
        ))
            ->because('a dependency-cycle diagnostic MUST identify only the cyclic path')
            ->toThrow(
                IntegrationFixtureError::class,
                message: 'Integration fixture dependency cycle: alpha -> bravo -> alpha.',
            );
    }

    #[Test]
    public function invalidChannelDataFailsProvisioningAndStillCleansUp(): void
    {
        $cleaned = false;
        $definitions = [
            new IntegrationFixtureDefinition(
                'database',
                function (IntegrationFixtureContext $context) use (&$cleaned): void {
                    $context->defer(static function () use (&$cleaned): void {
                        $cleaned = true;
                    });
                    $context->expose(
                        FixtureResource::empty(),
                        [3 => FixtureResource::empty()],
                    );
                },
            ),
        ];

        Expect::calling(fn() => IntegrationFixtureManager::provision(
            $definitions,
            'run-5',
            2,
            2,
            null,
        ))->toThrow(IntegrationFixtureError::class, matching: '/invalid channel resource/');

        Expect::value($cleaned)->toBeTrue();
    }

    #[Test]
    public function duplicateFixtureDefinitionsAreRejected(): void
    {
        $definitions = [
            new IntegrationFixtureDefinition('database', static function (): void {}),
            new IntegrationFixtureDefinition('database', static function (): void {}),
        ];

        Expect::calling(fn() => IntegrationFixtureManager::provision(
            $definitions,
            'run-7',
            1,
            1,
            null,
        ))
            ->because('integration fixture IDs MUST be unique')
            ->toThrow(
                IntegrationFixtureError::class,
                message: 'Integration fixture "database" is declared more than once.',
            );
    }

    #[Test]
    public function oversizedChannelPayloadsFailAndStillCleanUp(): void
    {
        $cleaned = false;
        $definitions = [
            new IntegrationFixtureDefinition(
                'database',
                function (IntegrationFixtureContext $context) use (&$cleaned): void {
                    $context->defer(static function () use (&$cleaned): void {
                        $cleaned = true;
                    });
                    $context->expose(FixtureResource::from([
                        'payload' => \str_repeat('x', 1_048_576),
                    ]));
                },
            ),
        ];

        Expect::calling(fn() => IntegrationFixtureManager::provision(
            $definitions,
            'run-7',
            1,
            1,
            null,
        ))->toThrow(IntegrationFixtureError::class, matching: '/1 MiB transport limit/');

        Expect::value($cleaned)->toBeTrue();
    }

    #[Test]
    public function transportFailuresRetainCleanupFailuresWithoutReplacingThePrimaryCause(): void
    {
        $cleanupFailure = new \RuntimeException('database cleanup failed');
        $definitions = [
            new IntegrationFixtureDefinition(
                'database',
                static function (IntegrationFixtureContext $context) use ($cleanupFailure): void {
                    $context->defer(static function () use ($cleanupFailure): void {
                        throw $cleanupFailure;
                    });
                    $context->expose(FixtureResource::from([
                        'payload' => \str_repeat('x', 1_048_576),
                    ]));
                },
            ),
        ];

        Expect::calling(static fn() => IntegrationFixtureManager::provision(
            $definitions,
            'run-8',
            1,
            1,
            null,
        ))
            ->because('transport diagnostics MUST include cleanup failures without replacing the primary failure')
            ->toThrow(static function (IntegrationFixtureError $error): void {
                Expect::value($error->getMessage())->toBe(
                    "Integration fixture \"resource catalog\" failed to provision: "
                    . "Integration resources for channel 1 exceed the 1 MiB transport limit.\n"
                    . 'Additionally, cleanup for integration fixture "database" failed: database cleanup failed.',
                );

                Expect::value($error->getPrevious())
                    ->because('the transport failure MUST remain the previous exception')
                    ->toBeInstanceOf(\LengthException::class);
            });
    }

    #[Test]
    public function aFixtureCannotReadAnUndeclaredDependency(): void
    {
        $definitions = [
            new IntegrationFixtureDefinition(
                'network',
                static fn(IntegrationFixtureContext $context) => $context->expose(
                    FixtureResource::from(['host' => '127.0.0.1']),
                ),
            ),
            new IntegrationFixtureDefinition(
                'database',
                static function (IntegrationFixtureContext $context): void {
                    $context->dependency('network');
                },
            ),
        ];

        Expect::calling(fn() => IntegrationFixtureManager::provision(
            $definitions,
            'run-8',
            1,
            1,
            null,
        ))->toThrow(IntegrationFixtureError::class, matching: '/undeclared dependency "network"/');
    }

    #[Test]
    public function aFixtureCannotReadADependencyForAChannelOutsideTheRun(): void
    {
        $definitions = [
            new IntegrationFixtureDefinition(
                'network',
                static fn(IntegrationFixtureContext $context) => $context->expose(
                    FixtureResource::from(['host' => '127.0.0.1']),
                ),
            ),
            new IntegrationFixtureDefinition(
                'database',
                static function (IntegrationFixtureContext $context): void {
                    $context->dependency('network', 2);
                },
                ['network'],
            ),
        ];

        Expect::calling(fn() => IntegrationFixtureManager::provision(
            $definitions,
            'run-9',
            1,
            1,
            null,
        ))->because('fixture dependencies MUST remain isolated to channels in the current run')
            ->toThrow(
                IntegrationFixtureError::class,
                message: 'Integration fixture "database" failed to provision: '
                    . 'Channel 2 is not part of this integration fixture run.',
            );
    }

}
