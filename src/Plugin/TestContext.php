<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Artifact\Attachments;
use Greenlight\Artifact\UnavailableAttachments;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\ServiceResolutionFailed;
use Greenlight\Harness\UnresolvableService;
use Greenlight\Test\SkipTest;
use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestId;

/**
 * Supplies the test instance, identity, attachments, and service access to plugins.
 *
 * The per-test service scope closes before `afterTest()`. A `service()` call
 * for a per-test service then throws. Other service scopes remain available.
 */
final readonly class TestContext
{
    public Attachments $attachments;

    /** @internal Greenlight constructs the test context. */
    public function __construct(
        public object $instance,
        public TestId $id,
        public TestDefinition $definition,
        private HarnessScopes $scopes,
        ?Attachments $attachments = null,
    ) {
        $this->attachments = $attachments ?? new UnavailableAttachments();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     *
     * @throws ServiceResolutionFailed when a service resolver cannot supply a valid service
     */
    public function service(string $type): object
    {
        $service = $this->scopes->resolve($type, 'plugin context for ' . $this->definition->class);

        if (!$service instanceof $type) {
            throw UnresolvableService::unknownType($type, 'plugin context for ' . $this->definition->class);
        }

        return $service;
    }

    /**
     * Stops the current attempt during `beforeTest()`. Code after the call does
     * not run.
     *
     * @param non-empty-string $reason
     *
     * @throws SkipTest
     */
    public function skip(string $reason): never
    {
        throw new SkipTest($reason);
    }
}
