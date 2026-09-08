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

final readonly class IntegrationFixtureTransportLimitTest
{
    #[Test]
    public function theTransportLimitAppliesToTheCompleteFixtureCatalog(): void
    {
        $trace = [];
        $definitions = [];

        foreach (['database', 'cache'] as $id) {
            $definitions[] = new IntegrationFixtureDefinition(
                $id,
                static function (IntegrationFixtureContext $context) use ($id, &$trace): void {
                    $trace[] = $id . ':start';
                    $context->defer(static function () use ($id, &$trace): void {
                        $trace[] = $id . ':stop';
                    });
                    $context->expose(FixtureResource::from([
                        'payload' => \str_repeat('x', 600_000),
                    ]));
                },
            );
        }

        Expect::that(static fn() => IntegrationFixtureManager::provision(
            $definitions,
            'aggregate-limit',
            1,
            1,
            null,
        ))
            ->because('two resources below 1 MiB can exceed the complete channel payload limit')
            ->toThrow(
                IntegrationFixtureError::class,
                message: 'Integration fixture "resource catalog" failed to provision: '
                    . 'Integration resources for channel 1 exceed the 1 MiB transport limit.',
            );

        Expect::that($trace)
            ->because('catalog rejection must release every acquired fixture in reverse order')
            ->toBe(['database:start', 'cache:start', 'cache:stop', 'database:stop']);
    }

    #[Test]
    public function aLaterChannelCannotExceedTheTransportLimitAfterAnEarlierChannelFits(): void
    {
        $trace = [];
        $definition = new IntegrationFixtureDefinition(
            'database',
            static function (IntegrationFixtureContext $context) use (&$trace): void {
                $trace[] = 'start';
                $context->defer(static function () use (&$trace): void {
                    $trace[] = 'stop';
                });
                $context->expose(
                    FixtureResource::from(['host' => 'localhost']),
                    [2 => FixtureResource::from(['payload' => \str_repeat('x', 1_048_576)])],
                );
            },
        );

        Expect::that(static fn() => IntegrationFixtureManager::provision(
            [$definition],
            'later-channel-limit',
            2,
            2,
            null,
        ))
            ->because('each channel must fit the transport limit after its overlay is applied')
            ->toThrow(
                IntegrationFixtureError::class,
                message: 'Integration fixture "resource catalog" failed to provision: '
                    . 'Integration resources for channel 2 exceed the 1 MiB transport limit.',
            );

        Expect::that($trace)
            ->because('a later channel failure must still release the acquired fixture')
            ->toBe(['start', 'stop']);
    }
}
