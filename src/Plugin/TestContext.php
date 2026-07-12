<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\UnresolvableService;

/**
 * Live runtime context of one test attempt.
 *
 * It carries the actual test instance, its identity and metadata, access to
 * the harness services in scope, and skip() to abandon the attempt from
 * beforeTest.
 *
 * service() is usable during beforeTest and the test itself; by the time
 * afterTest runs, the per-test scope has closed and service() throws.
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

    /**
     * Abandons this attempt and reports the test as skipped with the given
     * reason. Only meaningful during beforeTest; the signal it throws escapes
     * the subscriber, so nothing after the call runs.
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
