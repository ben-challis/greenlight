<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Doubles\Fake;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Plugin\Plugin;

/** Stores calls and returns one configured service. */
final class ServiceResolverProbe implements Fake, Plugin, ServiceResolver
{
    public int $calls = 0;

    public function __construct(private readonly ?object $service) {}

    #[\Override]
    public function resolve(string $type, array $attributes): ?object
    {
        ++$this->calls;

        return $this->service;
    }
}
