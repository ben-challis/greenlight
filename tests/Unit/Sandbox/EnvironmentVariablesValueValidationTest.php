<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Sandbox;

use Greenlight\Attribute\Test;
use Greenlight\Sandbox\EnvironmentVariables;

use function Greenlight\expect;

final readonly class EnvironmentVariablesValueValidationTest
{
    public function __construct(private EnvironmentVariables $environment) {}

    #[Test]
    public function aNullByteValueIsRejectedBeforeEnvironmentChannelsDiverge(): void
    {
        $name = 'GREENLIGHT_SANDBOX_NULL_VALUE';
        $this->environment->unset($name);

        expect()->calling(fn() => $this->environment->set($name, "before\0after"))
            ->because('a null byte value MUST be rejected before putenv truncates it')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Environment variable values cannot contain a null byte.',
            );
        expect(\getenv($name))
            ->because('a rejected value MUST NOT change the process environment')
            ->toBeFalse();
        expect(\array_key_exists($name, $_ENV))
            ->because('a rejected value MUST NOT change the ENV superglobal')
            ->toBeFalse();
        expect(\array_key_exists($name, $_SERVER))
            ->because('a rejected value MUST NOT change the SERVER superglobal')
            ->toBeFalse();
    }
}
