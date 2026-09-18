<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\ConfigLoader;
use Greenlight\Tests\Support\FixturePath;

use function Greenlight\expect;

final readonly class ConfigLoaderThrowableTest
{
    #[Test]
    public function wrapsErrorsThrownByConfigurationFiles(): void
    {
        $directory = FixturePath::get('ConfigFiles/ThrowingError');

        expect()->calling(static fn() => new ConfigLoader()->loadFromDirectory($directory))
            ->toThrow(
                static function (ConfigFileError $error) use ($directory): void {
                    expect($error->getMessage())
                        ->because('configuration errors MUST retain their type, source file, and message')
                        ->toBe(
                            'Configuration file "' . $directory . '/greenlight.php" threw '
                            . 'TypeError: config type exploded',
                        );
                    expect($error->getPrevious())
                        ->because('the wrapped configuration error MUST remain available as the cause')
                        ->toBeInstanceOf(\TypeError::class);
                    expect($error->getPrevious()->getMessage())->toBe('config type exploded');
                },
            );
    }
}
