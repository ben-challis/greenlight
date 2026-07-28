<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\ConfigLoader;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;

final readonly class ConfigLoaderThrowableTest
{
    #[Test]
    public function wrapsErrorsThrownByConfigurationFiles(): void
    {
        $directory = \dirname(__DIR__, 2) . '/Fixture/ConfigFiles/ThrowingError';

        try {
            new ConfigLoader()->loadFromDirectory($directory);
        } catch (ConfigFileError $error) {
            Expect::that($error->getMessage())
                ->because('configuration errors MUST retain their type, source file, and message')
                ->toBe(
                    'Configuration file "' . $directory . '/greenlight.php" threw '
                    . 'TypeError: config type exploded',
                )
                ->and($error->getPrevious())
                ->because('the wrapped configuration error MUST remain available as the cause')
                ->toBeInstanceOf(\TypeError::class);

            return;
        }

        Fail::because('Expected ConfigLoader to wrap the TypeError from the configuration file.');
    }
}
