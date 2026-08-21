<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Expect\Expect;
use Greenlight\Laravel\LaravelProcessState;
use Greenlight\Sandbox\EnvironmentVariables;
use Illuminate\Foundation\Application as LaravelApplication;

final readonly class LaravelProcessEnvironmentValidationTest
{
    public function __construct(private EnvironmentVariables $environment) {}

    #[Test]
    #[SkipUnless(ClassAvailable::class, LaravelApplication::class)]
    public function aNullByteEnvironmentIsRejectedBeforeProcessStateChanges(): void
    {
        $this->environment->set('APP_ENV', 'before-laravel');

        Expect::that(static fn(): LaravelProcessState => LaravelProcessState::setEnvironment("testing\0truncated"))
            ->because('a NUL environment MUST be rejected before putenv truncates it')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Laravel environment MUST NOT contain a null byte.',
            );
        Expect::that(\getenv('APP_ENV'))
            ->because('a rejected Laravel environment MUST NOT change the process environment')
            ->toBe('before-laravel');
        Expect::that($_ENV['APP_ENV'] ?? null)
            ->because('a rejected Laravel environment MUST NOT change the ENV superglobal')
            ->toBe('before-laravel');
        Expect::that($_SERVER['APP_ENV'] ?? null)
            ->because('a rejected Laravel environment MUST NOT change the SERVER superglobal')
            ->toBe('before-laravel');
    }
}
