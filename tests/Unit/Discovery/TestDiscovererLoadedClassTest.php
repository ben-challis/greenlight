<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\AutoloaderSandbox;
use Greenlight\Fixture\TempDirectory;

final readonly class TestDiscovererLoadedClassTest
{
    public function __construct(
        private TempDirectory $tempDirectory,
        private AutoloaderSandbox $autoloaders,
    ) {}

    #[Test]
    public function unresolvedClassPathsCannotMasqueradeAsTheSameFile(): void
    {
        $root = $this->tempDirectory->path();
        $expectedDirectory = $root . '/scanned';
        $actualDirectory = $root . '/autoloaded';
        \mkdir($expectedDirectory);
        \mkdir($actualDirectory);
        $expectedFile = $expectedDirectory . '/VanishedTest.php';
        $actualFile = $actualDirectory . '/VanishedTest.php';
        $namespace = 'GreenlightDiscoveryVanished' . \bin2hex(\random_bytes(6));
        $class = $namespace . '\\VanishedTest';
        $source = \sprintf(
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace %s;

                final class VanishedTest {}
                PHP,
            $namespace,
        );
        \file_put_contents($expectedFile, $source);
        \file_put_contents($actualFile, $source);

        $loader = static function (string $candidate) use ($class, $actualFile, $expectedFile): void {
            if ($candidate === $class) {
                require_once $actualFile;
                \unlink($actualFile);
                \unlink($expectedFile);
            }
        };
        $this->autoloaders->register($loader);

        Expect::that(
            static fn(): ExecutionPlan => new TestDiscoverer()->discover([$expectedDirectory]),
        )->because('discovery MUST reject class paths that it cannot resolve')->toThrow(
            DiscoveryError::class,
            message: \sprintf(
                'The autoloader loaded class "%s" from "%s". It expected the class in "%s". Only one file can declare a class.',
                $class,
                $actualFile,
                $expectedFile,
            ),
        );
    }

    #[Test]
    public function aClassLoadedFromAnotherFileFailsWithBothPaths(): void
    {
        $root = $this->tempDirectory->path();
        $expectedDirectory = $root . '/scanned';
        $actualDirectory = $root . '/autoloaded';
        \mkdir($expectedDirectory);
        \mkdir($actualDirectory);
        $expectedFile = $expectedDirectory . '/ShadowedTest.php';
        $actualFile = $actualDirectory . '/ShadowedTest.php';
        $class = 'GreenlightDiscoveryShadow\ShadowedTest';
        $source = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightDiscoveryShadow;

            final class ShadowedTest {}
            PHP;
        \file_put_contents($expectedFile, $source);
        \file_put_contents($actualFile, $source);

        $loader = static function (string $candidate) use ($class, $actualFile): void {
            if ($candidate === $class) {
                require_once $actualFile;
            }
        };
        $this->autoloaders->register($loader);

        Expect::that(
            static fn(): ExecutionPlan => new TestDiscoverer()->discover([$expectedDirectory]),
        )->because('discovery MUST reject a class that the autoloader loaded from another file')->toThrow(
            DiscoveryError::class,
            message: \sprintf(
                'The autoloader loaded class "%s" from "%s". It expected the class in "%s". Only one file can declare a class.',
                $class,
                $actualFile,
                $expectedFile,
            ),
        );
    }
}
