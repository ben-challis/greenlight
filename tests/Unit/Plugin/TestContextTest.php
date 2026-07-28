<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\UnresolvableService;
use Greenlight\Plugin\TestContext;

final class TestContextTest
{
    #[Test]
    public function serviceResolvesARegisteredHarnessService(): void
    {
        $registry = new HarnessRegistry([
            new ServiceDefinition(
                \ArrayObject::class,
                Scope::PerRun,
                static fn(): \ArrayObject => new \ArrayObject(['ready']),
            ),
        ]);
        $context = $this->context(new HarnessScopes($registry));

        $service = $context->service(\ArrayObject::class);

        Expect::that($service)
            ->because('the plugin context resolves a registered harness service')
            ->toBeInstanceOf(\ArrayObject::class)
            ->and($service->getArrayCopy())->toBe(['ready']);
    }

    #[Test]
    public function missingServiceNamesThePluginContext(): void
    {
        $context = $this->context(new HarnessScopes(new HarnessRegistry()));

        Expect::that(static fn(): object => $context->service(\ArrayObject::class))
            ->because('a missing service identifies the plugin context')
            ->toThrow(
                UnresolvableService::class,
                message: 'No harness service is registered for type "ArrayObject", required by "plugin context for Fixture\\PluginTest". '
                . 'Constructor injection resolves exact types only.',
            );
    }

    #[Test]
    public function attachmentsAreUnavailableWithoutAnActiveAttempt(): void
    {
        $context = $this->context(new HarnessScopes(new HarnessRegistry()));

        Expect::that(static function () use ($context): void {
            $context->attachments->text('note.txt', 'body');
        })
            ->because('a plugin context without an active attempt MUST reject attachments')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachments are not available outside an active test attempt.',
            );
    }

    #[Test]
    public function skipStopsTheAttemptWithTheExactReason(): void
    {
        $context = $this->context(new HarnessScopes(new HarnessRegistry()));

        Expect::that(static fn(): never => $context->skip('dependency is unavailable'))
            ->because('a plugin skip MUST preserve its reason for the test result')
            ->toThrow(SkipTest::class, message: 'dependency is unavailable');
    }

    private function context(HarnessScopes $scopes): TestContext
    {
        return new TestContext(
            new \stdClass(),
            new TestId('Fixture\\PluginTest', 'probe'),
            new TestMetadata('Fixture\\PluginTest', 'probe'),
            $scopes,
        );
    }
}
