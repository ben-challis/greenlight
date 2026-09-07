<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\GreenlightCli;

final class HelpOutputTest
{
    #[Test]
    #[DataSet('helpOptions')]
    public function ansiHighlightsHelpLabelsWithoutChangingTheText(string $helpOption): void
    {
        $root = \dirname(__DIR__, 2);
        $plain = GreenlightCli::run($root, [$helpOption], ['NO_COLOR' => '', 'CI' => 'false']);
        $colored = GreenlightCli::run($root, [$helpOption, '--ansi'], ['NO_COLOR' => '', 'CI' => 'true']);

        Expect::that($plain->exitCode)->toBe(0);
        Expect::that($plain->stdout)->toStartWith('Greenlight')->not()->toContain("\x1b[");
        Expect::that($plain->stderr)->toBe('');
        Expect::that($colored->exitCode)->toBe(0);
        Expect::that($colored->stderr)->toBe('');
        Expect::that(\preg_replace('/\x1b\[[0-9;]*m/', '', $colored->stdout))
            ->because('color preserves the help text and its spacing')
            ->toBe($plain->stdout);
        Expect::that($colored->stdout)
            ->toMatch('/\x1b\[[0-9;]+mGreenlight\x1b\[[0-9;]+m/')
            ->toMatch('/\x1b\[[0-9;]+mUsage:\x1b\[[0-9;]+m/')
            ->toMatch('/\x1b\[[0-9;]+mCommands:\x1b\[[0-9;]+m/')
            ->toMatch('/\x1b\[[0-9;]+mOptions:\x1b\[[0-9;]+m/')
            ->toMatch('/\x1b\[[0-9;]+mrun\x1b\[[0-9;]+m +Find and run tests/')
            ->toMatch('/\x1b\[[0-9;]+mcoverage:merge\x1b\[[0-9;]+m +Merge coverage/')
            ->toMatch('/\x1b\[[0-9;]+m--config=<path>\x1b\[[0-9;]+m +Use this configuration/')
            ->toMatch('/\x1b\[[0-9;]+m-h, --help\x1b\[[0-9;]+m +Show this help/')
            ->toMatch('/\x1b\[[0-9;]+m-V, --version\x1b\[[0-9;]+m +Show the version/');
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     */
    #[Test]
    #[DataSet('colorOverrides')]
    public function colorOverridesKeepHelpEscapeFree(array $arguments, array $environment): void
    {
        $root = \dirname(__DIR__, 2);
        $plain = GreenlightCli::run($root, ['--help'], ['NO_COLOR' => '', 'CI' => 'false']);
        $result = GreenlightCli::run($root, $arguments, $environment);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($result->stdout)->toBe($plain->stdout)->not()->toContain("\x1b[");
        Expect::that($result->stderr)->toBe('');
    }

    /** @return iterable<string, array{string}> */
    public static function helpOptions(): iterable
    {
        yield 'long help option' => ['--help'];
        yield 'short help option' => ['-h'];
    }

    /** @return iterable<string, array{list<string>, array<string, string>}> */
    public static function colorOverrides(): iterable
    {
        yield 'no ANSI after ANSI' => [
            ['--help', '--ansi', '--no-ansi'],
            ['NO_COLOR' => '', 'CI' => 'false'],
        ];
        yield 'no ANSI before ANSI' => [
            ['-h', '--no-ansi', '--ansi'],
            ['NO_COLOR' => '', 'CI' => 'false'],
        ];
        yield 'NO_COLOR enabled' => [
            ['--help', '--ansi'],
            ['NO_COLOR' => '1', 'CI' => 'false'],
        ];
        yield 'NO_COLOR set to zero' => [
            ['-h', '--ansi'],
            ['NO_COLOR' => '0', 'CI' => 'false'],
        ];
    }
}
