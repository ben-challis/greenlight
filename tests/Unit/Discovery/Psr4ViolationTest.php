<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ClassFileParser;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Fixture\SomewhereElse\MismatchTest;

final readonly class Psr4ViolationTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    private function discoveryErrorMessage(string $fixture): string
    {
        try {
            new TestDiscoverer()->discover([\dirname(__DIR__, 2) . '/Fixture/' . $fixture]);
        } catch (DiscoveryError $e) {
            return $e->getMessage();
        }

        Fail::because(\sprintf('Expected discovery of %s to fail.', $fixture));
    }

    #[Test]
    public function wrongNamespaceProducesATypedErrorNamingFileAndClass(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryPsr4Violation');

        Expect::that($message)->because('wrong namespace produces a typed error naming file and class')->toContain('The autoloader cannot load class');
        Expect::that($message)->because('wrong namespace produces a typed error naming file and class')->toContain(MismatchTest::class);
        Expect::that($message)->because('wrong namespace produces a typed error naming file and class')->toContain('MismatchTest.php');
    }

    #[Test]
    public function classNameNotMatchingFileNameProducesATypedError(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryClassNameMismatch');

        Expect::that($message)->because('class name not matching file name produces a typed error')->toContain('SomethingElseTest');
        Expect::that($message)->because('class name not matching file name produces a typed error')->toContain('WrongNameTest');
    }

    #[Test]
    public function fileWithoutAnyDeclarationProducesATypedError(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryNoClass');

        Expect::that($message)->because('a file without a declaration produces a typed error')->toContain('does not declare a class');
        Expect::that($message)->because('a file without a declaration produces a typed error')->toContain('NothingHereTest.php');
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

        if (!\is_string($resolvedFile)) {
            Fail::because('Expected the incomplete class fixture to have a canonical path.');
        }

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
