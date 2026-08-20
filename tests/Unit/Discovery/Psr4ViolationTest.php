<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ClassFileParser;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Fixture\SomewhereElse\MismatchTest;

final readonly class Psr4ViolationTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    /**
     * @return \Closure(): ExecutionPlan
     */
    private function discoverFixture(string $fixture): \Closure
    {
        $directory = \dirname(__DIR__, 2) . '/Fixture/' . $fixture;

        return static fn(): ExecutionPlan => new TestDiscoverer()->discover([$directory]);
    }

    #[Test]
    public function wrongNamespaceProducesATypedErrorNamingFileAndClass(): void
    {
        Expect::that($this->discoverFixture('DiscoveryPsr4Violation'))
            ->because('wrong namespace produces a typed error naming file and class')
            ->toThrow(static function (DiscoveryError $error): void {
                Expect::that($error->getMessage())->toContain('The autoloader cannot load class');
                Expect::that($error->getMessage())->toContain(MismatchTest::class);
                Expect::that($error->getMessage())->toContain('MismatchTest.php');
            });
    }

    #[Test]
    public function classNameNotMatchingFileNameProducesATypedError(): void
    {
        Expect::that($this->discoverFixture('DiscoveryClassNameMismatch'))
            ->because('class name not matching file name produces a typed error')
            ->toThrow(static function (DiscoveryError $error): void {
                Expect::that($error->getMessage())->toContain('SomethingElseTest');
                Expect::that($error->getMessage())->toContain('WrongNameTest');
            });
    }

    #[Test]
    public function fileWithoutAnyDeclarationProducesATypedError(): void
    {
        Expect::that($this->discoverFixture('DiscoveryNoClass'))
            ->because('a file without a declaration produces a typed error')
            ->toThrow(static function (DiscoveryError $error): void {
                Expect::that($error->getMessage())->toContain('does not declare a class');
                Expect::that($error->getMessage())->toContain('NothingHereTest.php');
            });
    }

    #[Test]
    public function unreadableClassFileProducesATypedErrorNamingTheFile(): void
    {
        $missing = \dirname(__DIR__, 2) . '/Fixture/MissingTest.php';

        Expect::that(static fn(): array => ClassFileParser::declarationsIn($missing))
            ->because('an unreadable class file produces a typed error naming the file')
            ->toThrow(
                DiscoveryError::class,
                matching: '/Greenlight cannot read test file ".*\/MissingTest\.php"/',
            );
    }

    #[Test]
    public function incompleteClassDeclarationsProduceATypedDiscoveryError(): void
    {
        $directory = $this->tempDirectory->subdirectory('incomplete-declaration');

        $file = $directory . '/IncompleteTest.php';
        \file_put_contents($file, "<?php\n\nclass");
        $resolvedFile = \realpath($file);

        Expect::that($resolvedFile)
            ->because('The incomplete class fixture MUST have a canonical path.')
            ->toBeString();

        Expect::that(
            static fn(): ExecutionPlan => new TestDiscoverer()->discover([$directory]),
        )
            ->because('an incomplete class declaration is not mistaken for a test class')
            ->toThrow(
                DiscoveryError::class,
                message: \sprintf(
                    'Test file "%s" does not declare a class, interface, trait, or enum.',
                    $resolvedFile,
                ),
            );
    }
}
