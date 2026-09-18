<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Tempest;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Internal\Process\EnvironmentBackup;
use Greenlight\Tempest\TempestProcessState;
use Tempest\Container\GenericContainer;

use function Greenlight\expect;

#[SkipUnless(ClassAvailable::class, GenericContainer::class)]
final readonly class TempestProcessStateTest
{
    #[Test]
    public function activationReplacesAndRestoresExistingProcessState(): void
    {
        $backup = EnvironmentBackup::capture('ENVIRONMENT');
        $originalContainer = GenericContainer::instance();
        $replacementContainer = new GenericContainer();
        $state = null;

        try {
            \putenv('ENVIRONMENT=process-original');
            $_ENV['ENVIRONMENT'] = null;
            $_SERVER['ENVIRONMENT'] = 'server-original';

            $state = TempestProcessState::activate('testing', $replacementContainer);

            expect(\getenv('ENVIRONMENT'))->toBe('testing');
            expect($_ENV['ENVIRONMENT'])->toBe('testing');
            expect($_SERVER['ENVIRONMENT'])->toBe('testing');
            expect(GenericContainer::instance())->toBe($replacementContainer);

            $state->restore();

            expect(\getenv('ENVIRONMENT'))->toBe('process-original');
            expect(\array_key_exists('ENVIRONMENT', $_ENV))->toBeTrue();
            expect($_ENV['ENVIRONMENT'])->toBeNull();
            expect($_SERVER['ENVIRONMENT'])->toBe('server-original');
            expect(GenericContainer::instance())->toBe($originalContainer);
        } finally {
            $state?->restore();
            $backup->restore();
            GenericContainer::setInstance($originalContainer);
        }
    }

    #[Test]
    public function restorationRemovesProcessStateThatWasInitiallyAbsent(): void
    {
        $backup = EnvironmentBackup::capture('ENVIRONMENT');
        $originalContainer = GenericContainer::instance();
        $state = null;

        try {
            \putenv('ENVIRONMENT');
            unset($_ENV['ENVIRONMENT'], $_SERVER['ENVIRONMENT']);

            $state = TempestProcessState::activate('testing');
            $state->restore();

            expect(\getenv('ENVIRONMENT'))->toBeFalse();
            expect(\array_key_exists('ENVIRONMENT', $_ENV))->toBeFalse();
            expect(\array_key_exists('ENVIRONMENT', $_SERVER))->toBeFalse();
            expect(GenericContainer::instance())->toBe($originalContainer);
        } finally {
            $state?->restore();
            $backup->restore();
            GenericContainer::setInstance($originalContainer);
        }
    }
}
