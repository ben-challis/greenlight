<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Fixture;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\EnvironmentSandbox;

final class EnvironmentSandboxTest
{
    #[Test]
    public function setMakesTheVariableVisibleEverywhere(): void
    {
        $name = 'GREENLIGHT_SANDBOX_TEST_SET';
        $sandbox = new EnvironmentSandbox();

        $sandbox->set($name, 'value');

        Expect::that(\getenv($name))->because('set makes the variable visible everywhere')->toBe('value');
        Expect::that($this->envValue($name))->toBe('value');
        Expect::that($this->serverValue($name))->toBe('value');

        $sandbox->dispose();

        Expect::that(\getenv($name))->because('set makes the variable visible everywhere')->toBeFalse();
        Expect::that($this->envHas($name))->toBeFalse();
        Expect::that($this->serverHas($name))->toBeFalse();
    }

    #[Test]
    public function disposeRestoresThePriorValue(): void
    {
        $name = 'GREENLIGHT_SANDBOX_TEST_RESTORE';
        \putenv($name . '=original');
        $_ENV[$name] = 'original';
        $_SERVER[$name] = 'original';

        try {
            $sandbox = new EnvironmentSandbox();
            $sandbox->set($name, 'changed');
            $sandbox->dispose();

            Expect::that(\getenv($name))->toBe('original');
            Expect::that($this->envValue($name))->toBe('original');
            Expect::that($this->serverValue($name))->toBe('original');
        } finally {
            \putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    #[Test]
    public function disposePreservesPresentFalseyOriginalValues(): void
    {
        $name = 'GREENLIGHT_SANDBOX_TEST_FALSEY_RESTORE';
        \putenv($name . '=');
        $_ENV[$name] = null;
        $_SERVER[$name] = false;

        try {
            $sandbox = new EnvironmentSandbox();
            $sandbox->set($name, 'changed');
            $sandbox->dispose();

            Expect::that(\getenv($name))
                ->because('dispose MUST distinguish present falsey values from absent values')
                ->toBe('');
            Expect::that($this->envHas($name))
                ->toBeTrue();
            Expect::that($this->envValue($name))
                ->toBeNull();
            Expect::that($this->serverHas($name))
                ->toBeTrue();
            Expect::that($this->serverValue($name))
                ->toBeFalse();
        } finally {
            \putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    #[Test]
    public function disposeRestoresEachEnvironmentChannelIndependently(): void
    {
        $suffix = \strtoupper(\bin2hex(\random_bytes(6)));
        $processAndServer = 'GREENLIGHT_SANDBOX_TEST_PROCESS_SERVER_' . $suffix;
        $envOnly = 'GREENLIGHT_SANDBOX_TEST_ENV_ONLY_' . $suffix;
        $sandbox = new EnvironmentSandbox();

        try {
            \putenv($processAndServer . '=process-original');
            unset($_ENV[$processAndServer]);
            $_SERVER[$processAndServer] = 'server-original';

            \putenv($envOnly);
            $_ENV[$envOnly] = 'env-original';
            unset($_SERVER[$envOnly]);

            $sandbox->set($processAndServer, 'changed');
            $sandbox->unset($envOnly);
            $sandbox->dispose();

            Expect::that(\getenv($processAndServer))
                ->because('dispose restores each environment channel independently')
                ->toBe('process-original');
            Expect::that($this->envHas($processAndServer))
                ->toBeFalse();
            Expect::that($this->serverValue($processAndServer))
                ->toBe('server-original');
            Expect::that(\getenv($envOnly))
                ->toBeFalse();
            Expect::that($this->envValue($envOnly))
                ->toBe('env-original');
            Expect::that($this->serverHas($envOnly))
                ->toBeFalse();
        } finally {
            $sandbox->dispose();
            \putenv($processAndServer);
            \putenv($envOnly);
            unset(
                $_ENV[$processAndServer],
                $_ENV[$envOnly],
                $_SERVER[$processAndServer],
                $_SERVER[$envOnly],
            );
        }
    }

    #[Test]
    public function unsetRemovesTheVariableAndDisposeBringsItBack(): void
    {
        $name = 'GREENLIGHT_SANDBOX_TEST_UNSET';
        \putenv($name . '=present');
        $_ENV[$name] = 'present';
        $_SERVER[$name] = 'present';

        try {
            $sandbox = new EnvironmentSandbox();
            $sandbox->unset($name);

            Expect::that(\getenv($name))->toBeFalse();
            Expect::that($this->envHas($name))->toBeFalse();
            Expect::that($this->serverHas($name))->toBeFalse();

            $sandbox->dispose();

            Expect::that(\getenv($name))->toBe('present');
            Expect::that($this->envValue($name))->toBe('present');
            Expect::that($this->serverValue($name))->toBe('present');
        } finally {
            \putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    #[Test]
    public function theFirstRecordedOriginalWinsAcrossMultipleSets(): void
    {
        $name = 'GREENLIGHT_SANDBOX_TEST_FIRST_WINS';
        \putenv($name . '=first');
        $_ENV[$name] = 'first';
        $_SERVER[$name] = 'first';

        try {
            $sandbox = new EnvironmentSandbox();
            $sandbox->set($name, 'second');
            $sandbox->set($name, 'third');
            $sandbox->unset($name);
            $sandbox->dispose();

            Expect::that(\getenv($name))->toBe('first');
            Expect::that($this->envValue($name))->toBe('first');
            Expect::that($this->serverValue($name))->toBe('first');
        } finally {
            \putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    #[Test]
    #[DataSet('invalidNames')]
    public function setRejectsInvalidNamesWithoutChangingTheEnvironment(string $name): void
    {
        $processBefore = \getenv();
        $envBefore = $_ENV;
        $serverBefore = $_SERVER;
        $sandbox = new EnvironmentSandbox();

        Expect::that(static fn() => $sandbox->set($name, 'value'))
            ->because('set rejects invalid names before it changes the environment')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Environment variable names cannot be empty or contain "=" or a null byte.',
            );

        Expect::that(\getenv())
            ->because('set rejects invalid names before it changes the environment')
            ->toBe($processBefore);
        Expect::that($_ENV)
            ->toBe($envBefore);
        Expect::that($_SERVER)
            ->toBe($serverBefore);
    }

    #[Test]
    #[DataSet('invalidNames')]
    public function unsetRejectsInvalidNamesWithoutChangingTheEnvironment(string $name): void
    {
        $processBefore = \getenv();
        $envBefore = $_ENV;
        $serverBefore = $_SERVER;
        $sandbox = new EnvironmentSandbox();

        Expect::that(static fn() => $sandbox->unset($name))
            ->because('unset rejects invalid names before it changes the environment')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Environment variable names cannot be empty or contain "=" or a null byte.',
            );

        Expect::that(\getenv())
            ->because('unset rejects invalid names before it changes the environment')
            ->toBe($processBefore);
        Expect::that($_ENV)
            ->toBe($envBefore);
        Expect::that($_SERVER)
            ->toBe($serverBefore);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty name' => [''];
        yield 'equals sign' => ['GREENLIGHT_INVALID=NAME'];
        yield 'null byte' => ["GREENLIGHT_INVALID\0NAME"];
    }

    /** Tells the analyzer that the superglobal offset remains variable after a change. */
    private function envValue(string $name): mixed
    {
        return $_ENV[$name] ?? null;
    }

    private function serverValue(string $name): mixed
    {
        return $_SERVER[$name] ?? null;
    }

    private function envHas(string $name): bool
    {
        return \array_key_exists($name, $_ENV);
    }

    private function serverHas(string $name): bool
    {
        return \array_key_exists($name, $_SERVER);
    }
}
