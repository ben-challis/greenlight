<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Tempest;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Process\EnvironmentBackup;
use Greenlight\Tempest\TempestProcessState;
use Tempest\Container\GenericContainer;

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

            Expect::value(\getenv('ENVIRONMENT'))->toBe('testing');
            Expect::value($_ENV['ENVIRONMENT'])->toBe('testing');
            Expect::value($_SERVER['ENVIRONMENT'])->toBe('testing');
            Expect::value(GenericContainer::instance())->toBe($replacementContainer);

            $state->restore();

            Expect::value(\getenv('ENVIRONMENT'))->toBe('process-original');
            Expect::value(\array_key_exists('ENVIRONMENT', $_ENV))->toBeTrue();
            Expect::value($_ENV['ENVIRONMENT'])->toBeNull();
            Expect::value($_SERVER['ENVIRONMENT'])->toBe('server-original');
            Expect::value(GenericContainer::instance())->toBe($originalContainer);
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

            Expect::value(\getenv('ENVIRONMENT'))->toBeFalse();
            Expect::value(\array_key_exists('ENVIRONMENT', $_ENV))->toBeFalse();
            Expect::value(\array_key_exists('ENVIRONMENT', $_SERVER))->toBeFalse();
            Expect::value(GenericContainer::instance())->toBe($originalContainer);
        } finally {
            $state?->restore();
            $backup->restore();
            GenericContainer::setInstance($originalContainer);
        }
    }
}
