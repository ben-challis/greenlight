<?php

declare(strict_types=1);

namespace Greenlight\Hyperf;

/** Selects the lifetime of the Hyperf application container. */
enum ContainerLifetime
{
    /** One container belongs to one physical Greenlight worker. */
    case Worker;

    /** One container belongs to one test attempt. */
    case TestAttempt;
}
