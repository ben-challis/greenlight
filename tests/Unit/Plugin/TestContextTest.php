<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\UnresolvableService;
use Greenlight\Plugin\TestContext;
use Greenlight\Test\SkipTest;
use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestId;

final class TestContextTest
{
    #[Test]
    public function serviceResolvesARegisteredHarnessService(): void
    {
        $definitions = [
            new ServiceDefinition(
                \ArrayObject::class,
                Scope::PerWorker,
                static fn(): \ArrayObject => new \ArrayObject(['ready']),
            ),
        ];
        $context = $this->context(new HarnessScopes($definitions));

        $service = $context->service(\ArrayObject::class);

        Expect::that($service)
            ->because('the plugin context resolves a registered harness service')
            ->toBeInstanceOf(\ArrayObject::class);
        Expect::that($service->getArrayCopy())->toBe(['ready']);
    }

    #[Test]
    public function missingServiceNamesThePluginContext(): void
    {
        $context = $this->context(new HarnessScopes());

        Expect::that(static fn(): object => $context->service(\ArrayObject::class))
            ->because('a missing service identifies the plugin context')
            ->toThrow(
                UnresolvableService::class,
                message: 'No harness service is registered for type "ArrayObject", required by "plugin context for Fixture\\PluginTest". '
                . 'Constructor injection resolves exact types only.',
            );
    }

    #[Test]
    public function serviceRejectsAccessAfterTheTestScopeCloses(): void
    {
        $definitions = [
            new ServiceDefinition(
                \ArrayObject::class,
                Scope::PerTest,
                static fn(): \ArrayObject => new \ArrayObject(['ready']),
            ),
        ];
        $scopes = new HarnessScopes($definitions);
        $scopes->openTest();
        $context = $this->context($scopes);

        Expect::that($context->service(\ArrayObject::class))
            ->because('the plugin context can resolve a service while the test scope is open')
            ->toBeInstanceOf(\ArrayObject::class);

        $scopes->closeTest();

        Expect::that(static fn(): object => $context->service(\ArrayObject::class))
            ->because('the plugin context MUST NOT expose a service after the test scope closes')
            ->toThrow(\LogicException::class, message: 'No test scope is open.');
    }

    #[Test]
    public function attachmentsAreUnavailableWithoutAnActiveAttempt(): void
    {
        $context = $this->context(new HarnessScopes());

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
        $context = $this->context(new HarnessScopes());

        Expect::that(static fn(): never => $context->skip('dependency is unavailable'))
            ->because('a plugin skip MUST preserve its reason for the test result')
            ->toThrow(SkipTest::class, message: 'dependency is unavailable');
    }

    private function context(HarnessScopes $scopes): TestContext
    {
        return new TestContext(
            new \stdClass(),
            new TestId('Fixture\\PluginTest', 'probe'),
            new TestDefinition('Fixture\\PluginTest', 'probe'),
            $scopes,
        );
    }
}
