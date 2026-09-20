<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ClassFileParser;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\SomewhereElse\MismatchTest;
use Greenlight\Tests\Support\FixturePath;

use function Greenlight\expect;

final readonly class Psr4ViolationTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    /**
     * @return \Closure(): ExecutionPlan
     */
    private function discoverFixture(string $fixture): \Closure
    {
        $directory = FixturePath::get($fixture);

        return static fn(): ExecutionPlan => new TestDiscoverer()->discover([$directory]);
    }

    #[Test]
    public function wrongNamespaceProducesATypedErrorNamingFileAndClass(): void
    {
        expect()->calling($this->discoverFixture('DiscoveryPsr4Violation'))
            ->because('wrong namespace produces a typed error naming file and class')
            ->toThrow(static function (DiscoveryError $error): void {
                expect($error->getMessage())
                    ->toContain('The autoloader cannot load class')
                    ->toContain(MismatchTest::class)
                    ->toContain('MismatchTest.php');
            });
    }

    #[Test]
    public function classNameNotMatchingFileNameProducesATypedError(): void
    {
        expect()->calling($this->discoverFixture('DiscoveryClassNameMismatch'))
            ->because('class name not matching file name produces a typed error')
            ->toThrow(static function (DiscoveryError $error): void {
                expect($error->getMessage())
                    ->toContain('SomethingElseTest')
                    ->toContain('WrongNameTest');
            });
    }

    #[Test]
    public function fileWithoutAnyDeclarationProducesATypedError(): void
    {
        expect()->calling($this->discoverFixture('DiscoveryNoClass'))
            ->because('a file without a declaration produces a typed error')
            ->toThrow(static function (DiscoveryError $error): void {
                expect($error->getMessage())
                    ->toContain('does not declare a class')
                    ->toContain('NothingHereTest.php');
            });
    }

    #[Test]
    public function unreadableClassFileProducesATypedErrorNamingTheFile(): void
    {
        $missing = FixturePath::get('MissingTest.php');

        expect()->calling(static fn(): array => ClassFileParser::declarationsIn($missing))
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

        expect($resolvedFile)
            ->because('The incomplete class fixture MUST have a canonical path.')
            ->toBeString();

        expect()->calling(
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
