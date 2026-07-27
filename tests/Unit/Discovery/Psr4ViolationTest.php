<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Tests\Fixture\SomewhereElse\MismatchTest;

final class Psr4ViolationTest
{
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

        Expect::that($message)->because('file without any declaration produces a typed error')->toContain('does not declare a class');
        Expect::that($message)->because('file without any declaration produces a typed error')->toContain('NothingHereTest.php');
    }
}
