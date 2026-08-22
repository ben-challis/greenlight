<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Process\EnvironmentBackup;
use Greenlight\Laravel\LaravelProcessState;
use Illuminate\Foundation\Application as LaravelApplication;

final readonly class LaravelProcessNullEnvironmentTest
{
    #[Test]
    #[SkipUnless(ClassAvailable::class, LaravelApplication::class)]
    public function restorePreservesNullEnvironmentValuesAsPresent(): void
    {
        $backup = EnvironmentBackup::capture('APP_ENV');
        $state = null;

        try {
            \putenv('APP_ENV=process-original');
            $_ENV['APP_ENV'] = null;
            $_SERVER['APP_ENV'] = null;

            $state = LaravelProcessState::setEnvironment('testing');
            $state->restore();

            Expect::that(\getenv('APP_ENV'))
                ->because('restore MUST preserve present null environment values')
                ->toBe('process-original');
            Expect::that(\array_key_exists('APP_ENV', $_ENV))
                ->toBeTrue();
            Expect::that($_ENV['APP_ENV'])
                ->toBeNull();
            Expect::that(\array_key_exists('APP_ENV', $_SERVER))
                ->toBeTrue();
            Expect::that($_SERVER['APP_ENV'])
                ->toBeNull();
        } finally {
            $state?->restore();
            $backup->restore();
        }
    }
}
