<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Tempest;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\Subprocess;

final readonly class TempestFrameworkUnavailableTest
{
    #[Test]
    public function publicRequirementProbeRejectsAMissingFramework(): void
    {
        $root = \dirname(__DIR__, 3);

        $result = Subprocess::run($root, [
            \PHP_BINARY,
            '-n',
            '-r',
            <<<'PHP'
            $source = $argv[1];

            spl_autoload_register(static function (string $class) use ($source): void {
                $prefix = 'Greenlight\\';

                if (!str_starts_with($class, $prefix)) {
                    return;
                }

                $path = $source . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

                if (is_file($path)) {
                    require $path;
                }
            });

            if (class_exists(\Tempest\Core\FrameworkKernel::class)) {
                exit(2);
            }

            try {
                \Greenlight\Tempest\TempestFrameworkRequirement::check();
            } catch (\Greenlight\Tempest\TempestBridgeError $error) {
                fwrite(STDOUT, $error->getMessage());
                exit(0);
            }

            exit(3);
            PHP,
            $root . '/src',
        ]);

        Expect::that($result->exitCode)
            ->because('the public framework probe MUST reject an installation without Tempest')
            ->toBe(0);
        Expect::that($result->stdout)
            ->because('the error MUST explain how to install the required Tempest framework')
            ->toBe(
                'The Tempest framework is not available. TempestPlugin requires '
                . 'tempest/framework 3.18 or later in major version 3. Install the framework '
                . 'before you activate the plugin.',
            );
    }
}
