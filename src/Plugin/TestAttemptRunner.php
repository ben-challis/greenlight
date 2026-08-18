<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/** Runs each complete test attempt in a plugin-defined runtime boundary. */
interface TestAttemptRunner extends Plugin
{
    /**
     * @template T
     *
     * @param \Closure(): T $attempt
     *
     * @return T
     */
    public function runTestAttempt(\Closure $attempt): mixed;
}
