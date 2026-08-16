<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\TerminalCapabilities;
use Greenlight\Expect\Expect;

final class TerminalCapabilitiesTest
{
    #[Test]
    public function aPlainTtyIsInteractiveWithColor(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: [], noAnsiFlag: false);

        Expect::that($capabilities->interactive)->because('a plain TTY is interactive with color')->toBeTrue();
        Expect::that($capabilities->color)->toBeTrue();
    }

    #[Test]
    public function nonTtyIsNeverInteractive(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: false, env: [], noAnsiFlag: false);

        Expect::that($capabilities->interactive)->because('non TTY is never interactive')->toBeFalse();
        Expect::that($capabilities->color)->toBeFalse();
    }

    #[Test]
    public function theNoAnsiFlagForcesNonInteractive(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: [], noAnsiFlag: true);

        Expect::that($capabilities->interactive)->because('the no ANSI flag forces non interactive')->toBeFalse();
        Expect::that($capabilities->color)->toBeFalse();
    }

    #[Test]
    #[DataSet('truthyCiValues')]
    public function truthyCiForcesNonInteractiveEvenWithATty(string $value): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: ['CI' => $value], noAnsiFlag: false);

        Expect::that($capabilities->interactive)->toBeFalse();
        Expect::that($capabilities->color)->toBeFalse();
    }

    #[Test]
    #[DataSet('falsyCiValues')]
    public function falsyCiValuesDoNotDisableInteractivity(string|false $value): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: ['CI' => $value], noAnsiFlag: false);

        Expect::that($capabilities->interactive)
            ->because('a falsey CI value MUST preserve terminal interactivity')
            ->toBeTrue();
        Expect::that($capabilities->color)
            ->because('a falsey CI value MUST preserve terminal color')
            ->toBeTrue();
    }

    #[Test]
    public function noColorStripsColorButKeepsInteractivity(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: ['NO_COLOR' => '1'], noAnsiFlag: false);

        Expect::that($capabilities->interactive)->because('no color strips color but keeps interactivity')->toBeTrue();
        Expect::that($capabilities->color)->toBeFalse();
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
            ->toBeTrue();
        Expect::that($capabilities->color)->toBeFalse();
    }

    #[Test]
    public function anEmptyNoColorIsIgnored(): void
    {
        $capabilities = TerminalCapabilities::detect(stdoutIsTty: true, env: ['NO_COLOR' => ''], noAnsiFlag: false);

        Expect::that($capabilities->color)->because('an empty no color is ignored')->toBeTrue();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function truthyCiValues(): iterable
    {
        yield 'true string' => ['true'];
        yield 'one string' => ['1'];
        yield 'yes string' => ['yes'];
    }

    /**
     * @return iterable<string, array{string|false}>
     */
    public static function falsyCiValues(): iterable
    {
        yield 'empty string' => [''];
        yield 'zero string' => ['0'];
        yield 'lowercase false string' => ['false'];
        yield 'uppercase false string' => ['FALSE'];
        yield 'boolean false' => [false];
    }
}
