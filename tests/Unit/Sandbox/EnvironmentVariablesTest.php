<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Sandbox;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\EnvironmentVariables;

use function Greenlight\expect;

final class EnvironmentVariablesTest
{
    #[Test]
    public function setMakesTheVariableVisibleEverywhere(): void
    {
        $name = 'GREENLIGHT_SANDBOX_TEST_SET';
        $sandbox = new EnvironmentVariables();

        $sandbox->set($name, 'value');

        expect(\getenv($name))->because('set makes the variable visible everywhere')->toBe('value');
        expect($this->envValue($name))->toBe('value');
        expect($this->serverValue($name))->toBe('value');

        $sandbox->dispose();

        expect(\getenv($name))->because('set makes the variable visible everywhere')->toBeFalse();
        expect($this->envHas($name))->toBeFalse();
        expect($this->serverHas($name))->toBeFalse();
    }

    #[Test]
    public function disposeRestoresThePriorValue(): void
    {
        $name = 'GREENLIGHT_SANDBOX_TEST_RESTORE';
        \putenv($name . '=original');
        $_ENV[$name] = 'original';
        $_SERVER[$name] = 'original';

        try {
            $sandbox = new EnvironmentVariables();
            $sandbox->set($name, 'changed');
            $sandbox->dispose();

            expect(\getenv($name))->toBe('original');
            expect($this->envValue($name))->toBe('original');
            expect($this->serverValue($name))->toBe('original');
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
            $sandbox = new EnvironmentVariables();
            $sandbox->set($name, 'changed');
            $sandbox->dispose();

            expect(\getenv($name))
                ->because('dispose MUST distinguish present falsey values from absent values')
                ->toBe('');
            expect($this->envHas($name))
                ->toBeTrue();
            expect($this->envValue($name))
                ->toBeNull();
            expect($this->serverHas($name))
                ->toBeTrue();
            expect($this->serverValue($name))
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
        $sandbox = new EnvironmentVariables();

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

            expect(\getenv($processAndServer))
                ->because('dispose restores each environment channel independently')
                ->toBe('process-original');
            expect($this->envHas($processAndServer))
                ->toBeFalse();
            expect($this->serverValue($processAndServer))
                ->toBe('server-original');
            expect(\getenv($envOnly))
                ->toBeFalse();
            expect($this->envValue($envOnly))
                ->toBe('env-original');
            expect($this->serverHas($envOnly))
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
            $sandbox = new EnvironmentVariables();
            $sandbox->unset($name);

            expect(\getenv($name))->toBeFalse();
            expect($this->envHas($name))->toBeFalse();
            expect($this->serverHas($name))->toBeFalse();

            $sandbox->dispose();

            expect(\getenv($name))->toBe('present');
            expect($this->envValue($name))->toBe('present');
            expect($this->serverValue($name))->toBe('present');
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
            $sandbox = new EnvironmentVariables();
            $sandbox->set($name, 'second');
            $sandbox->set($name, 'third');
            $sandbox->unset($name);
            $sandbox->dispose();

            expect(\getenv($name))->toBe('first');
            expect($this->envValue($name))->toBe('first');
            expect($this->serverValue($name))->toBe('first');
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
        $sandbox = new EnvironmentVariables();

        expect()->calling(static fn() => $sandbox->set($name, 'value'))
            ->because('set rejects invalid names before it changes the environment')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Environment variable names cannot be empty or contain "=" or a null byte.',
            );

        expect(\getenv())
            ->because('set rejects invalid names before it changes the environment')
            ->toBe($processBefore);
        expect($_ENV)
            ->toBe($envBefore);
        expect($_SERVER)
            ->toBe($serverBefore);
    }

    #[Test]
    #[DataSet('invalidNames')]
    public function unsetRejectsInvalidNamesWithoutChangingTheEnvironment(string $name): void
    {
        $processBefore = \getenv();
        $envBefore = $_ENV;
        $serverBefore = $_SERVER;
        $sandbox = new EnvironmentVariables();

        expect()->calling(static fn() => $sandbox->unset($name))
            ->because('unset rejects invalid names before it changes the environment')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Environment variable names cannot be empty or contain "=" or a null byte.',
            );

        expect(\getenv())
            ->because('unset rejects invalid names before it changes the environment')
            ->toBe($processBefore);
        expect($_ENV)
            ->toBe($envBefore);
        expect($_SERVER)
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
