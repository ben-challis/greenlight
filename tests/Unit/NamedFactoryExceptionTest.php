<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Plugin\CommandSetupFailed;
use Greenlight\Cli\Watch\WatchScanFailed;
use Greenlight\Execution\RunPolicyError;
use Greenlight\Expect\Expect;
use Greenlight\IntegrationFixture\IntegrationFixtureError;
use Greenlight\Psr15\Psr15Error;

final readonly class NamedFactoryExceptionTest
{
    /**
     * @param class-string<\Throwable> $exception
     */
    #[Test]
    #[DataSet('namedFactoryExceptions')]
    public function namedFactoryExceptionsCannotBeConstructedDirectly(string $exception): void
    {
        $constructor = new \ReflectionClass($exception)->getConstructor();

        Expect::that($constructor?->isPrivate())
            ->because('named exception factories MUST be the only construction interface')
            ->toBeTrue();
    }

    /**
     * @return iterable<string, array{class-string<\Throwable>}>
     */
    public static function namedFactoryExceptions(): iterable
    {
        yield AttachmentError::class => [AttachmentError::class];
        yield CommandSetupFailed::class => [CommandSetupFailed::class];
        yield WatchScanFailed::class => [WatchScanFailed::class];
        yield RunPolicyError::class => [RunPolicyError::class];
        yield IntegrationFixtureError::class => [IntegrationFixtureError::class];
        yield Psr15Error::class => [Psr15Error::class];
    }
}
