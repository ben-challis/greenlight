<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Sandbox\Autoloaders;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class TestDiscovererSymlinkTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Autoloaders $autoloaders,
    ) {}

    #[Test]
    public function followsADirectorySymlink(): void
    {
        $tests = $this->tempDirectory->subdirectory('linked/tests');
        $shared = $this->tempDirectory->subdirectory('linked/shared');
        \file_put_contents($shared . '/SharedTest.php', '<?php');
        \symlink($shared, $tests . '/shared');

        expect(new TestDiscoverer()->testFiles([$tests]))
            ->because('discovery MUST find test files below a symlinked directory')
            ->toBe([\realpath($tests) . '/shared/SharedTest.php']);
    }

    #[Test]
    public function stopsAtASymlinkCycle(): void
    {
        $tests = $this->tempDirectory->subdirectory('cycle/tests');
        $nested = $this->tempDirectory->subdirectory('cycle/tests/nested');
        \file_put_contents($nested . '/NestedTest.php', '<?php');
        \symlink($tests, $nested . '/loop');

        expect(new TestDiscoverer()->testFiles([$tests]))
            ->because('a symlink to an ancestor MUST not repeat or loop the walk')
            ->toBe([\realpath($tests) . '/nested/NestedTest.php']);
    }

    #[Test]
    public function aSymlinkedTestFileEntersThePlanOnce(): void
    {
        $suffix = \bin2hex(\random_bytes(6));
        $className = 'SymlinkProbe' . $suffix . 'Test';
        $tests = $this->tempDirectory->subdirectory('file/tests');
        $real = $this->tempDirectory->subdirectory('file/tests/real');
        \file_put_contents($real . '/' . $className . '.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace GreenlightSymlinkProbe;

            use Greenlight\Attribute\Test;

            final class {$className}
            {
                #[Test]
                public function runs(): void {}
            }
            PHP);
        $alias = $this->tempDirectory->subdirectory('file/tests/alias');
        \symlink($real . '/' . $className . '.php', $alias . '/' . $className . '.php');
        $this->autoloaders->register(static function (string $class) use ($real, $className): void {
            if ($class === 'GreenlightSymlinkProbe\\' . $className) {
                require_once $real . '/' . $className . '.php';
            }
        });

        $plan = new TestDiscoverer()->discover([$tests]);

        expect(\count($plan))
            ->because('two paths to one test file MUST make one plan entry')
            ->toBe(1);
        expect(new TestDiscoverer()->testFiles([$tests]))
            ->because('discovery keeps the path that sorts first')
            ->toBe([\realpath($tests) . '/alias/' . $className . '.php']);
    }
}
