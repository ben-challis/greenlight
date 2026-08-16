<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\EnvironmentBackup;
use Greenlight\Laravel\LaravelProcessState;
use Illuminate\Foundation\Application as LaravelApplication;

final readonly class LaravelProcessZeroEnvironmentTest
{
    #[Test]
    #[SkipUnless(ClassAvailable::class, LaravelApplication::class)]
    public function restorePreservesAZeroProcessEnvironment(): void
    {
        $backup = EnvironmentBackup::capture('APP_ENV');
        $state = null;

        try {
            \putenv('APP_ENV=0');
            $_ENV['APP_ENV'] = 'environment-original';
            $_SERVER['APP_ENV'] = 'server-original';

            $state = LaravelProcessState::setEnvironment('testing');
            $state->restore();

            Expect::that(\getenv('APP_ENV'))
                ->because('restore MUST preserve a zero process environment')
                ->toBe('0');
            Expect::that($_ENV['APP_ENV'])
                ->toBe('environment-original');
            Expect::that($_SERVER['APP_ENV'])
                ->toBe('server-original');
        } finally {
            $state?->restore();
            $backup->restore();
        }
    }
}
