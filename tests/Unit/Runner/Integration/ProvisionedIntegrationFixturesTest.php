<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Integration;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\FixtureResource;
use Greenlight\Runner\Integration\ProvisionedIntegrationFixtures;

final readonly class ProvisionedIntegrationFixturesTest
{
    #[Test]
    public function aFixtureCannotExposeResourcesMoreThanOnce(): void
    {
        $session = new ProvisionedIntegrationFixtures();
        $session->expose('database', FixtureResource::empty(), []);

        Expect::that(static function () use ($session): void {
            $session->expose('database', FixtureResource::empty(), []);
        })
            ->because('an integration fixture MUST expose resources at most once')
            ->toThrow(
                \LogicException::class,
                message: 'Integration fixture "database" exposed resources more than once.',
            );
    }

    #[Test]
    public function anUnprovisionedDependencyCannotBeRead(): void
    {
        $session = new ProvisionedIntegrationFixtures();

        Expect::that(static fn(): FixtureResource => $session->dependency('database', null))
            ->because('an integration fixture dependency MUST be provisioned before use')
            ->toThrow(
                \LogicException::class,
                message: 'Integration fixture dependency "database" has not been provisioned.',
            );
    }

    /**
     * @param \Closure(ProvisionedIntegrationFixtures): void $mutate
     */
    #[Test]
    #[DataSet('closedSessionMutations')]
    public function aClosedSessionRejectsMutation(\Closure $mutate): void
    {
        $session = new ProvisionedIntegrationFixtures();
        $session->close();

        Expect::that(static function () use ($mutate, $session): void {
            $mutate($session);
        })
            ->because('a closed integration fixture session MUST reject further mutation')
            ->toThrow(
                \LogicException::class,
                message: 'Integration fixture session is already closed.',
            );
    }

    /**
     * @return iterable<string, array{\Closure(ProvisionedIntegrationFixtures): void}>
     */
    public static function closedSessionMutations(): iterable
    {
        yield 'expose resources' => [
            static function (ProvisionedIntegrationFixtures $session): void {
                $session->expose('database', FixtureResource::empty(), []);
            },
        ];
        yield 'ensure resources are exposed' => [
            static function (ProvisionedIntegrationFixtures $session): void {
                $session->ensureExposed('database');
            },
        ];
        yield 'defer cleanup' => [
            static function (ProvisionedIntegrationFixtures $session): void {
                $session->defer('database', static function (): void {});
            },
        ];
    }
}
