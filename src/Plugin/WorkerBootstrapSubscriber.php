<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * Worker-side initialization after fixture data arrives and before harness
 * providers and service resolvers are consumed.
 */
interface WorkerBootstrapSubscriber extends Plugin
{
    public function onWorkerBootstrap(WorkerBootstrapContext $context): void;
}
