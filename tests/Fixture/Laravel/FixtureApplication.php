<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Laravel;

use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;

/**
 * Builds a minimal application with the fixture services. The base path
 * includes GREENLIGHT_CHANNEL so parallel workers do not share bootstrap
 * caches.
 */
final class FixtureApplication
{
    public static function create(): ApplicationContract
    {
        $base = self::stateDir();

        if (!\is_dir($base . '/bootstrap/cache')) {
            \mkdir($base . '/bootstrap/cache', 0o777, true);
        }

        return Application::configure(basePath: $base)
            ->withProviders([FixtureServiceProvider::class])
            ->create();
    }

    private static function stateDir(): string
    {
        $channel = \getenv('GREENLIGHT_CHANNEL');

        return \sys_get_temp_dir() . '/greenlight-laravel-fixture/'
            . (\is_string($channel) && $channel !== '' ? $channel : '0');
    }
}
