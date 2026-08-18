<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/** Runs one physical worker in a plugin-defined runtime boundary. */
interface WorkerRuntimeRunner extends Plugin
{
    /**
     * @template T
     *
     * @param \Closure(): T $worker
     *
     * @return T
     */
    public function runWorker(\Closure $worker): mixed;
}
