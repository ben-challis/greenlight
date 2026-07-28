<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Fixture;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\EnvironmentSandbox;

final readonly class EnvironmentSandboxValueValidationTest
{
    public function __construct(private EnvironmentSandbox $environment) {}

    #[Test]
    public function aNullByteValueIsRejectedBeforeEnvironmentChannelsDiverge(): void
    {
        $name = 'GREENLIGHT_SANDBOX_NULL_VALUE';
        $this->environment->unset($name);

        Expect::that(fn() => $this->environment->set($name, "before\0after"))
            ->because('a null byte value MUST be rejected before putenv truncates it')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Environment variable values cannot contain a null byte.',
            )
            ->and([
                \getenv($name),
                \array_key_exists($name, $_ENV),
                \array_key_exists($name, $_SERVER),
            ])
            ->because('a rejected value MUST NOT change any environment channel')
            ->toBe([false, false, false]);
    }
}
