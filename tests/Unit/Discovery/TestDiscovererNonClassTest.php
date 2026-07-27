<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;

final readonly class TestDiscovererNonClassTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    #[DataSet('nonClassDeclarations')]
    public function nonClassDeclarationsWithMatchingFileNamesAreIgnored(string $kind): void
    {
        $directory = $this->tempDirectory->subdirectory($kind);
        $file = $directory . '/IgnoredTest.php';
        \file_put_contents($file, \sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\n%s IgnoredTest {}\n",
            $kind,
        ));
        $discoverer = new TestDiscoverer();

        Expect::that($discoverer->testFiles([$directory]))
            ->because('a matching non-class declaration is a test-file candidate')
            ->toBe([$file]);
        Expect::that($discoverer->discover([$directory])->count())
            ->because('a matching non-class declaration MUST be ignored')
            ->toBe(0);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonClassDeclarations(): iterable
    {
        yield 'interface' => ['interface'];

        yield 'trait' => ['trait'];

        yield 'enum' => ['enum'];
    }
}
