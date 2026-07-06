<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Tests\Support\Check;

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

        Check::true(\str_contains($message, 'not autoloadable'), 'message to state the failure: ' . $message);
        Check::true(
            \str_contains($message, \Greenlight\Tests\Fixture\SomewhereElse\MismatchTest::class),
            'message to name the parsed class: ' . $message,
        );
        Check::true(\str_contains($message, 'MismatchTest.php'), 'message to name the file: ' . $message);
    }

    #[Test]
    public function classNameNotMatchingFileNameProducesATypedError(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryClassNameMismatch');

        Check::true(\str_contains($message, 'SomethingElseTest'), 'message to name the declared class: ' . $message);
        Check::true(\str_contains($message, 'WrongNameTest'), 'message to name the expected class: ' . $message);
    }

    #[Test]
    public function fileWithoutAnyDeclarationProducesATypedError(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryNoClass');

        Check::true(
            \str_contains($message, 'does not declare any class'),
            'message to state the failure: ' . $message,
        );
        Check::true(\str_contains($message, 'NothingHereTest.php'), 'message to name the file: ' . $message);
    }
}
