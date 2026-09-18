<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\ConfigLoader;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\FixturePath;

final readonly class ConfigLoaderThrowableTest
{
    #[Test]
    public function wrapsErrorsThrownByConfigurationFiles(): void
    {
        $directory = FixturePath::get('ConfigFiles/ThrowingError');

        Expect::calling(static fn() => new ConfigLoader()->loadFromDirectory($directory))
            ->toThrow(
                static function (ConfigFileError $error) use ($directory): void {
                    Expect::value($error->getMessage())
                        ->because('configuration errors MUST retain their type, source file, and message')
                        ->toBe(
                            'Configuration file "' . $directory . '/greenlight.php" threw '
                            . 'TypeError: config type exploded',
                        );
                    Expect::value($error->getPrevious())
                        ->because('the wrapped configuration error MUST remain available as the cause')
                        ->toBeInstanceOf(\TypeError::class);
                    Expect::value($error->getPrevious()->getMessage())->toBe('config type exploded');
                },
            );
    }
}
