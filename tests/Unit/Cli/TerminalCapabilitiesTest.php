<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\TerminalCapabilities;
use Greenlight\Expect\Expect;

final class TerminalCapabilitiesTest
{
    #[Test]
    public function aPlainTtyIsInteractiveWithColour(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: [], noAnsiFlag: false);

        Expect::that($capabilities->interactive)->toBeTrue()
            ->and($capabilities->colour)->toBeTrue();
    }

    #[Test]
    public function nonTtyIsNeverInteractive(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: false, env: [], noAnsiFlag: false);

        Expect::that($capabilities->interactive)->toBeFalse()
            ->and($capabilities->colour)->toBeFalse();
    }

    #[Test]
    public function theNoAnsiFlagForcesNonInteractive(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: [], noAnsiFlag: true);

        Expect::that($capabilities->interactive)->toBeFalse()
            ->and($capabilities->colour)->toBeFalse();
    }

    #[Test]
    public function truthyCiForcesNonInteractiveEvenWithATty(): void
    {
        foreach (['true', '1', 'yes'] as $value) {
            $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: ['CI' => $value], noAnsiFlag: false);

            Expect::that($capabilities->interactive)->toBeFalse()
                ->and($capabilities->colour)->toBeFalse();
        }
    }

    #[Test]
    public function falsyCiValuesDoNotDisableInteractivity(): void
    {
        foreach (['', '0', 'false', 'FALSE', false] as $value) {
            $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: ['CI' => $value], noAnsiFlag: false);

            Expect::that($capabilities->interactive)->toBeTrue();
        }
    }

    #[Test]
    public function noColorStripsColourButKeepsInteractivity(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: ['NO_COLOR' => '1'], noAnsiFlag: false);

        Expect::that($capabilities->interactive)->toBeTrue()
            ->and($capabilities->colour)->toBeFalse();
    }

    #[Test]
    public function anEmptyNoColorIsIgnored(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: ['NO_COLOR' => ''], noAnsiFlag: false);

        Expect::that($capabilities->colour)->toBeTrue();
    }
}
