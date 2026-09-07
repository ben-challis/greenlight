<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Hyperf;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\PhpSubprocess;

final readonly class HyperfScanDirectoryRaceTest
{
    #[Test]
    #[DataRow([true, 0, 'Class scan completed.'])]
    #[DataRow([false, 1, 'HyperfPlugin cannot open scan lock'])]
    public function aConcurrentDirectoryCreationDoesNotPreventTheClassScan(bool $created, int $exitCode, string $output): void
    {
        $result = PhpSubprocess::run(\dirname(__DIR__, 3), ['-r', <<<'PHP'
        namespace Hyperf\Di {
            final class ClassLoader
            {
                public static function init(): void
                {
                    echo 'Class scan completed.';
                }
            }
        }

        namespace {
            require 'vendor/autoload.php';

            final class ConcurrentDirectory
            {
                public $context;
                private static bool $exists = false;

                public function url_stat(string $path, int $flags): array|false
                {
                    return self::$exists ? ['mode' => 0040777] : false;
                }

                public function mkdir(string $path, int $mode, int $options): bool
                {
                    self::$exists = $_SERVER['argv'][1] === 'created';

                    return false;
                }

                public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
                {
                    return true;
                }

                public function stream_lock(int $operation): bool
                {
                    return true;
                }
            }

            stream_wrapper_register('concurrent-directory', ConcurrentDirectory::class);
            $plugin = new \Greenlight\Hyperf\HyperfPlugin('concurrent-directory://application');

            try {
                new \ReflectionMethod($plugin, 'initializeClassLoader')->invoke($plugin, 'concurrent-directory://application');
            } catch (\Greenlight\Hyperf\HyperfBridgeError $error) {
                echo $error->getMessage();
                exit(1);
            }
        }
        PHP, $created ? 'created' : 'missing']);

        Expect::that($result->exitCode)->toBe($exitCode);
        Expect::that($result->stdout)->toContain($output);
        Expect::that($result->stderr)->toBe('');
    }
}
