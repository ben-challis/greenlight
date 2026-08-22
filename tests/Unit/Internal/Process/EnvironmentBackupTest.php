<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Internal\Process;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Process\EnvironmentBackup;

final readonly class EnvironmentBackupTest
{
    #[Test]
    public function setChangesEachChannelAndRestorePreservesTheirIndependentStates(): void
    {
        $name = $this->name('SET');
        \putenv($name . '=process-original');
        unset($_ENV[$name]);
        $_SERVER[$name] = null;
        $backup = EnvironmentBackup::capture($name);

        try {
            $backup->set('changed');

            Expect::that(\getenv($name))->toBe('changed');
            Expect::that($_ENV[$name])->toBe('changed');
            Expect::that($_SERVER[$name])->toBe('changed');

            $backup->restore();

            Expect::that(\getenv($name))->toBe('process-original');
            Expect::that(\array_key_exists($name, $_ENV))->toBeFalse();
            Expect::that(\array_key_exists($name, $_SERVER))
                ->because('restore MUST preserve an explicit null server value')
                ->toBeTrue();
            Expect::that($_SERVER[$name])->toBeNull();
        } finally {
            \putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    #[Test]
    public function unsetRemovesEachChannelAndRestorePreservesFalseyOriginals(): void
    {
        $name = $this->name('UNSET');
        \putenv($name);
        $_ENV[$name] = null;
        $_SERVER[$name] = false;
        $backup = EnvironmentBackup::capture($name);

        try {
            $backup->unset();

            Expect::that(\getenv($name))->toBeFalse();
            Expect::that(\array_key_exists($name, $_ENV))->toBeFalse();
            Expect::that(\array_key_exists($name, $_SERVER))->toBeFalse();

            $backup->restore();

            Expect::that(\getenv($name))->toBeFalse();
            Expect::that(\array_key_exists($name, $_ENV))->toBeTrue();
            Expect::that($_ENV[$name])->toBeNull();
            Expect::that(\array_key_exists($name, $_SERVER))->toBeTrue();
            Expect::that($_SERVER[$name])->toBeFalse();
        } finally {
            \putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    #[Test]
    #[DataSet('invalidNames')]
    public function captureRejectsAnInvalidNameBeforeEnvironmentAccess(string $name): void
    {
        Expect::that(static fn() => EnvironmentBackup::capture($name))
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Environment variable names cannot be empty or contain "=" or a null byte.',
            );
    }

    #[Test]
    public function setRejectsANullByteBeforeChangingTheEnvironment(): void
    {
        $name = $this->name('INVALID_VALUE');
        $backup = EnvironmentBackup::capture($name);

        try {
            Expect::that(static fn() => $backup->set("before\0after"))
                ->toThrow(
                    \InvalidArgumentException::class,
                    message: 'Environment variable values cannot contain a null byte.',
                );

            Expect::that(\getenv($name))->toBeFalse();
            Expect::that(\array_key_exists($name, $_ENV))->toBeFalse();
            Expect::that(\array_key_exists($name, $_SERVER))->toBeFalse();
        } finally {
            $backup->restore();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'equals sign' => ['GREENLIGHT_INVALID=NAME'];
        yield 'null byte' => ["GREENLIGHT_INVALID\0NAME"];
    }

    private function name(string $case): string
    {
        return 'GREENLIGHT_ENVIRONMENT_BACKUP_' . $case . '_' . \strtoupper(\bin2hex(\random_bytes(6)));
    }
}
