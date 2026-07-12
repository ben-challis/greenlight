<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
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

        throw new \RuntimeException(\sprintf('Expected discovery of %s to fail.', $fixture));
    }

    #[Test]
    public function wrongNamespaceProducesATypedErrorNamingFileAndClass(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryPsr4Violation');

        Expect::that($message)->toContain('not autoloadable');
        Expect::that($message)->toContain(MismatchTest::class);
        Expect::that($message)->toContain('MismatchTest.php');
    }

    #[Test]
    public function classNameNotMatchingFileNameProducesATypedError(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryClassNameMismatch');

        Expect::that($message)->toContain('SomethingElseTest');
        Expect::that($message)->toContain('WrongNameTest');
    }

    #[Test]
    public function fileWithoutAnyDeclarationProducesATypedError(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryNoClass');

        Expect::that($message)->toContain('does not declare any class');
        Expect::that($message)->toContain('NothingHereTest.php');
    }
}
