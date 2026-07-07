<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\UnresolvableService;

/**
 * Live runtime context of one test attempt: the actual test instance, its
 * identity and metadata, and access to the harness services in scope.
 * Observation and service access only; lifecycle control stays with the
 * worker. service() is usable during beforeTest and the test itself; by the
 * time afterTest runs, the per-test scope has closed and service() throws.
 */
final readonly class TestContext
{
    public function __construct(
        public object $instance,
        public TestId $id,
        public TestMetadata $metadata,
        private HarnessScopes $scopes,
    ) {}

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     *
     * @throws UnresolvableService
     */
    public function service(string $type): object
    {
        $service = $this->scopes->resolve($type, 'plugin context for ' . $this->metadata->class);

        if (!$service instanceof $type) {
            throw UnresolvableService::unknownType($type, 'plugin context for ' . $this->metadata->class);
        }

        return $service;
    }
}
