<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\TerminalCapabilities;
use Greenlight\Expect\Expect;

final class TerminalCapabilitiesTest
{
    #[Test]
    public function aPlainTtyIsInteractiveWithColor(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: [], noAnsiFlag: false);

        Expect::that($capabilities->interactive)->because('a plain TTY is interactive with color')->toBeTrue()
            ->and($capabilities->color)->toBeTrue();
    }

    #[Test]
    public function nonTtyIsNeverInteractive(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: false, env: [], noAnsiFlag: false);

        Expect::that($capabilities->interactive)->because('non TTY is never interactive')->toBeFalse()
            ->and($capabilities->color)->toBeFalse();
    }

    #[Test]
    public function theNoAnsiFlagForcesNonInteractive(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: [], noAnsiFlag: true);

        Expect::that($capabilities->interactive)->because('the no ANSI flag forces non interactive')->toBeFalse()
            ->and($capabilities->color)->toBeFalse();
    }

    #[Test]
    public function truthyCiForcesNonInteractiveEvenWithATty(): void
    {
        foreach (['true', '1', 'yes'] as $value) {
            $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: ['CI' => $value], noAnsiFlag: false);

            Expect::that($capabilities->interactive)->toBeFalse()
                ->and($capabilities->color)->toBeFalse();
        }
    }

    #[Test]
    public function falsyCiValuesDoNotDisableInteractivity(): void
    {
        foreach (['', '0', 'false', 'FALSE', false] as $value) {
            $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: ['CI' => $value], noAnsiFlag: false);

            Expect::that($capabilities->interactive)
                ->because('a falsey CI value MUST preserve terminal interactivity')
                ->toBeTrue()
                ->and($capabilities->color)
                ->because('a falsey CI value MUST preserve terminal color')
                ->toBeTrue();
        }
    }

    #[Test]
    public function noColorStripsColorButKeepsInteractivity(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: ['NO_COLOR' => '1'], noAnsiFlag: false);

        Expect::that($capabilities->interactive)->because('no color strips color but keeps interactivity')->toBeTrue()
            ->and($capabilities->color)->toBeFalse();
    }

    #[Test]
    public function aZeroNoColorValueStillDisablesColor(): void
    {
        $capabilities = TerminalCapabilities::detect(
            stdoutIsTty: true,
            env: ['NO_COLOR' => '0'],
            noAnsiFlag: false,
        );

        Expect::that($capabilities->interactive)
            ->because('a zero NO_COLOR value keeps interactivity but disables color')
            ->toBeTrue()
            ->and($capabilities->color)
            ->toBeFalse();
    }

    #[Test]
    public function anEmptyNoColorIsIgnored(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: ['NO_COLOR' => ''], noAnsiFlag: false);

        Expect::that($capabilities->color)->because('an empty no color is ignored')->toBeTrue();
    }
}
