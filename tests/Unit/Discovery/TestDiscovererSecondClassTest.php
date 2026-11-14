<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Sandbox\Autoloaders;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class TestDiscovererSecondClassTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Autoloaders $autoloaders,
    ) {}

    #[Test]
    public function aSecondTestClassInAFileFailsDiscovery(): void
    {
        $suffix = \bin2hex(\random_bytes(6));
        $className = 'FirstProbe' . $suffix . 'Test';
        $second = 'SecondProbe' . $suffix . 'Test';
        $directory = $this->tempDirectory->subdirectory('second-class');
        $file = $directory . '/' . $className . '.php';
        \file_put_contents($file, <<<PHP
            <?php

            declare(strict_types=1);

            namespace GreenlightSecondClass;

            use Greenlight\Attribute\Test;

            final class {$className}
            {
                #[Test]
                public function runs(): void {}
            }

            abstract class AbstractHelper{$suffix}
            {
                #[Test]
                public function inherited(): void {}
            }

            final class {$second}
            {
                #[Test]
                public function neverRuns(): void {}
            }
            PHP);
        $this->autoloaders->register(static function (string $class) use ($file, $className): void {
            if ($class === 'GreenlightSecondClass\\' . $className) {
                require_once $file;
            }
        });
        $real = (string) \realpath($file);

        expect()->calling(static fn() => new TestDiscoverer()->discover([$directory]))
            ->toThrow(DiscoveryError::class, message: \sprintf(
                'Test file "%s" declares test class "GreenlightSecondClass\%s" after "GreenlightSecondClass\%s". Greenlight runs only the class that matches the file name. Move "GreenlightSecondClass\%s" to its own file.',
                $real,
                $second,
                $className,
                $second,
            ));
    }
}
