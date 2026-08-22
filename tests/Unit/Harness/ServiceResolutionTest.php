<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\ServiceResolution;
use Greenlight\Harness\ServiceResolutionFailed;

final readonly class ServiceResolutionTest
{
    #[Test]
    public function unhandledResultContainsNoServiceOrError(): void
    {
        $resolution = ServiceResolution::unhandled();

        Expect::that($resolution->isUnhandled())->toBeTrue();
        Expect::that($resolution->isResolved())->toBeFalse();
        Expect::that($resolution->isFailed())->toBeFalse();
        Expect::that($resolution->value())->toBeNull();
        Expect::that($resolution->service(...))->toThrow(\LogicException::class);
        Expect::that($resolution->error(...))->toThrow(\LogicException::class);
    }

    #[Test]
    public function resolvedResultContainsOnlyTheService(): void
    {
        $service = new \stdClass();
        $resolution = ServiceResolution::resolved($service);

        Expect::that($resolution->isUnhandled())->toBeFalse();
        Expect::that($resolution->isResolved())->toBeTrue();
        Expect::that($resolution->isFailed())->toBeFalse();
        Expect::that($resolution->service())->toBe($service);
        Expect::that($resolution->value())->toBe($service);
        Expect::that($resolution->error(...))->toThrow(\LogicException::class);
    }

    #[Test]
    public function failedResultContainsOnlyTheError(): void
    {
        $error = new class ('Resolution failed.') extends ServiceResolutionFailed {};
        $resolution = ServiceResolution::failed($error);

        Expect::that($resolution->isUnhandled())->toBeFalse();
        Expect::that($resolution->isResolved())->toBeFalse();
        Expect::that($resolution->isFailed())->toBeTrue();
        Expect::that($resolution->error())->toBe($error);
        Expect::that($resolution->service(...))->toThrow(\LogicException::class);
        Expect::that($resolution->value(...))->toThrow($error);
    }
}
