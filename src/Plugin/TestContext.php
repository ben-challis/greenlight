<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Core\Artifact\Attachments;
use Greenlight\Core\Artifact\UnavailableAttachments;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\UnresolvableService;

/**
 * service() is available during beforeTest() and the test. The per-test
 * service scope closes before afterTest(), so service() throws during
 * afterTest().
 */
final readonly class TestContext
{
    public Attachments $attachments;

    public function __construct(
        public object $instance,
        public TestId $id,
        public TestMetadata $metadata,
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
     * Stops the current attempt during beforeTest(). Code after the call does
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
