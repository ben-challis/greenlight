<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Core\ErrorTrap;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Runner\CpuCores;

final readonly class CpuCoresFallbackTest
{
    public function __construct(private EnvironmentSandbox $environment) {}

    #[Test]
    #[Isolated]
    public function unavailablePlatformProbesUseTheConservativeDefault(): void
    {
        $root = \dirname(__DIR__, 3);
        $this->environment->set('PATH', '');
        $openBasedir = $root . \PATH_SEPARATOR . \sys_get_temp_dir();
        $previousOpenBasedir = \ini_set('open_basedir', $openBasedir);

        Expect::that($previousOpenBasedir)
            ->because('the isolated fixture MUST restrict access to platform CPU metadata')
            ->not()
            ->toBeFalse();

        $count = ErrorTrap::run(
            static fn(): int => CpuCores::count(),
            $warning,
        );

        Expect::that($count)
            ->because('an unavailable platform CPU count MUST use the conservative default')
            ->toBe(4);

        if (\PHP_OS_FAMILY === 'Linux') {
            Expect::that($warning)
                ->because('the restricted procfs path proves the primary platform probe was unavailable')
                ->toContain('/proc/cpuinfo');
        }
    }
}
