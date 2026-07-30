<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Integration;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\FixtureResource;
use Greenlight\Plugin\IntegrationFixtureContext;
use Greenlight\Plugin\IntegrationFixtureDefinition;
use Greenlight\Plugin\IntegrationFixtureProvider;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Runner\Integration\IntegrationFixtureError;
use Greenlight\Runner\Integration\IntegrationFixtureManager;

final class IntegrationFixtureManagerTest
{
    #[Test]
    public function dependenciesProvisionFirstAndCleanupRunsInReverseAcquisitionOrder(): void
    {
        $trace = [];
        $observedContext = null;

        $session = IntegrationFixtureManager::provision(
            PluginRegistry::orchestratorSide([
                $this->provider([
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
                ]),
            ]),
            'run-1',
            4,
            2,
            [2, 3],
        );

        $channelOne = $session->forChannel(1)->fixture('database');
        $channelTwo = $session->forChannel(2)->fixture('database');

        Expect::that($trace)->toBe(['network:start', 'database:start'])
            ->and($observedContext)->toBe(['run-1', 4, [1, 2], [2, 3]])
            ->and($channelOne->string('database'))->toBe('test_1')
            ->and($channelTwo->string('database'))->toBe('test_2')
            ->and($session->close())->toBe([])
            ->and($trace)->toBe([
                'network:start',
                'database:start',
                'database:stop',
                'network:stop',
            ])
            ->and($session->close())->toBe([]);
    }

    #[Test]
    public function partialProvisioningCleansEverythingAlreadyAcquired(): void
    {
        $trace = [];
        $provider = $this->provider([
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
        ]);

        Expect::that(fn() => IntegrationFixtureManager::provision(
            PluginRegistry::orchestratorSide([$provider]),
            'run-2',
            2,
            2,
            null,
        ))->toThrow(IntegrationFixtureError::class, matching: '/second.*could not start/');

        Expect::that($trace)->toBe([
            'first:start',
            'second:start',
            'second:stop',
            'first:stop',
        ]);
    }

    #[Test]
    public function missingDependenciesAndCyclesFailBeforeProvisioning(): void
    {
        $missing = $this->provider([
            new IntegrationFixtureDefinition('database', static function (): void {}, ['network']),
        ]);

        Expect::that(fn() => IntegrationFixtureManager::provision(
            PluginRegistry::orchestratorSide([$missing]),
            'run-3',
            1,
            1,
            null,
        ))->toThrow(IntegrationFixtureError::class, matching: '/missing fixture "network"/');

        $cycle = $this->provider([
            new IntegrationFixtureDefinition('alpha', static function (): void {}, ['bravo']),
            new IntegrationFixtureDefinition('bravo', static function (): void {}, ['alpha']),
        ]);

        Expect::that(fn() => IntegrationFixtureManager::provision(
            PluginRegistry::orchestratorSide([$cycle]),
            'run-4',
            1,
            1,
            null,
        ))->toThrow(IntegrationFixtureError::class, matching: '/alpha -> bravo -> alpha/');
    }

    #[Test]
    public function invalidChannelDataFailsProvisioningAndStillCleansUp(): void
    {
        $cleaned = false;
        $provider = $this->provider([
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
        ]);

        Expect::that(fn() => IntegrationFixtureManager::provision(
            PluginRegistry::orchestratorSide([$provider]),
            'run-5',
            2,
            2,
            null,
        ))->toThrow(IntegrationFixtureError::class, matching: '/invalid channel resource/');

        Expect::that($cleaned)->toBeTrue();
    }

    #[Test]
    public function providerFailuresAreReportedAsIntegrationFixtureErrors(): void
    {
        $provider = new class implements IntegrationFixtureProvider {
            #[\Override]
            public function integrationFixtures(): array
            {
                throw new \RuntimeException('provider exploded');
            }
        };

        Expect::that(fn() => IntegrationFixtureManager::provision(
            PluginRegistry::orchestratorSide([$provider]),
            'run-6',
            1,
            1,
            null,
        ))->toThrow(IntegrationFixtureError::class, matching: '/provider.*provider exploded/');
    }

    #[Test]
    public function duplicateFixtureIdsAcrossProvidersAreRejected(): void
    {
        $providers = [
            $this->provider([
                new IntegrationFixtureDefinition('database', static function (): void {}),
            ]),
            $this->provider([
                new IntegrationFixtureDefinition('database', static function (): void {}),
            ]),
        ];

        Expect::that(fn() => IntegrationFixtureManager::provision(
            PluginRegistry::orchestratorSide($providers),
            'run-7',
            1,
            1,
            null,
        ))
            ->because('integration fixture IDs MUST be unique across every provider')
            ->toThrow(
                IntegrationFixtureError::class,
                message: 'Integration fixture "database" is declared more than once.',
            );
    }

    #[Test]
    public function oversizedChannelPayloadsFailAndStillCleanUp(): void
    {
        $cleaned = false;
        $provider = $this->provider([
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
        ]);

        Expect::that(fn() => IntegrationFixtureManager::provision(
            PluginRegistry::orchestratorSide([$provider]),
            'run-7',
            1,
            1,
            null,
        ))->toThrow(IntegrationFixtureError::class, matching: '/1 MiB transport limit/');

        Expect::that($cleaned)->toBeTrue();
    }

    #[Test]
    public function aFixtureCannotReadAnUndeclaredDependency(): void
    {
        $provider = $this->provider([
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
        ]);

        Expect::that(fn() => IntegrationFixtureManager::provision(
            PluginRegistry::orchestratorSide([$provider]),
            'run-8',
            1,
            1,
            null,
        ))->toThrow(IntegrationFixtureError::class, matching: '/undeclared dependency "network"/');
    }

    #[Test]
    public function aFixtureCannotReadADependencyForAChannelOutsideTheRun(): void
    {
        $provider = $this->provider([
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
        ]);

        Expect::that(fn() => IntegrationFixtureManager::provision(
            PluginRegistry::orchestratorSide([$provider]),
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

    /**
     * @param list<IntegrationFixtureDefinition> $definitions
     */
    private function provider(array $definitions): IntegrationFixtureProvider
    {
        return new readonly class ($definitions) implements IntegrationFixtureProvider {
            /**
             * @param list<IntegrationFixtureDefinition> $definitions
             */
            public function __construct(private array $definitions) {}

            #[\Override]
            public function integrationFixtures(): array
            {
                return $this->definitions;
            }
        };
    }
}
