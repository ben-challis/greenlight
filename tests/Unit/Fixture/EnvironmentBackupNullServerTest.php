<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Fixture;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\EnvironmentBackup;

final readonly class EnvironmentBackupNullServerTest
{
    #[Test]
    public function restorePreservesANullServerValueAsPresent(): void
    {
        $name = 'GREENLIGHT_ENVIRONMENT_BACKUP_NULL_SERVER_' . \strtoupper(\bin2hex(\random_bytes(6)));
        \putenv($name . '=process-original');
        $_ENV[$name] = 'env-original';
        $_SERVER[$name] = null;
        $backup = EnvironmentBackup::capture($name);

        try {
            \putenv($name . '=changed');
            $_ENV[$name] = 'changed';
            $_SERVER[$name] = 'changed';

            $backup->restore();

            Expect::that(\getenv($name))
                ->because('restore MUST preserve explicit null presence in the server environment')
                ->toBe('process-original')
                ->and($_ENV[$name] ?? null)
                ->toBe('env-original')
                ->and(\array_key_exists($name, $_SERVER))
                ->toBeTrue()
                ->and($_SERVER[$name])
                ->toBeNull();
        } finally {
            \putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
}
