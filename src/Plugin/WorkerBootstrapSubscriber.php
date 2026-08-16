<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * Initializes one worker after fixture data arrives and before Greenlight uses
 * harness providers or service resolvers.
 */
interface WorkerBootstrapSubscriber extends Plugin
{
    public function onWorkerBootstrap(WorkerBootstrapContext $context): void;
}
