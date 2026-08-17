<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\ConfigLoader;
use Greenlight\Expect\Expect;

final readonly class ConfigLoaderThrowableTest
{
    #[Test]
    public function wrapsErrorsThrownByConfigurationFiles(): void
    {
        $directory = \dirname(__DIR__, 2) . '/Fixture/ConfigFiles/ThrowingError';

        Expect::that(static fn() => new ConfigLoader()->loadFromDirectory($directory))
            ->toThrow(
                static function (ConfigFileError $error) use ($directory): void {
                    Expect::that($error->getMessage())
                        ->because('configuration errors MUST retain their type, source file, and message')
                        ->toBe(
                            'Configuration file "' . $directory . '/greenlight.php" threw '
                            . 'TypeError: config type exploded',
                        );
                    Expect::that($error->getPrevious())
                        ->because('the wrapped configuration error MUST remain available as the cause')
                        ->toBeInstanceOf(\TypeError::class);
                    Expect::that($error->getPrevious()?->getMessage())->toBe('config type exploded');
                },
            );
    }
}
