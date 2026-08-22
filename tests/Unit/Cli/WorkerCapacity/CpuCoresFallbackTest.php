<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\WorkerCapacity;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Cli\WorkerCapacity\CpuCores;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Tests\Support\FilesystemRestriction;

final readonly class CpuCoresFallbackTest
{
    public function __construct(private EnvironmentVariables $environment) {}

    #[Test]
    #[Isolated]
    public function unavailablePlatformProbesUseTheConservativeDefault(): void
    {
        $root = \dirname(__DIR__, 4);
        $this->environment->set('PATH', '');
        FilesystemRestriction::toProject($root);

        $count = ErrorTrap::run(
            static fn() => CpuCores::count(),
            $warning,
        );

        Expect::that($count)
            ->because('an unavailable platform CPU count MUST use the conservative default')
            ->toBe(4);

        Expect::that($warning)
            ->because('unavailable platform CPU probes MUST not leak engine diagnostics')
            ->toBeNull();
    }
}
