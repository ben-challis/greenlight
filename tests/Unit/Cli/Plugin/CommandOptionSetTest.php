<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Plugin;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Application;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\MemoryStream;

use function Greenlight\expect;

final readonly class CommandOptionSetTest
{
    public function __construct(private Cleanup $cleanup) {}

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataSet('optionsOfOtherCommands')]
    public function aCommandRejectsAnOptionThatItDoesNotUse(array $arguments, string $option, string $command): void
    {
        [$exitCode, $stderr] = $this->run(['--no-ansi', ...$arguments]);

        expect($exitCode)
            ->because('an option of another command is a usage error')
            ->toBe(64);
        expect($stderr)
            ->because('the option exists, so the message MUST name the command instead of an unknown option')
            ->toBe(\sprintf("greenlight: Option %s does not apply to the \"%s\" command.\n", $option, $command));
    }

    /** @return iterable<string, array{list<string>, string, string}> */
    public static function optionsOfOtherCommands(): iterable
    {
        yield 'coverage:diff with workers' => [['coverage:diff', '--workers=2'], '--workers', 'coverage:diff'];
        yield 'profile:report with watch' => [['profile:report', '--watch'], '--watch', 'profile:report'];
        yield 'run with baseline' => [['run', '--baseline=a.json'], '--baseline', 'run'];
        yield 'default run with output' => [['--output=helper.php'], '--output', 'run'];
        yield 'ide-helper with reporter' => [['ide-helper', '--reporter=plain'], '--reporter', 'ide-helper'];
    }

    #[Test]
    public function anOptionThatNoCommandDefinesIsStillUnknown(): void
    {
        [$exitCode, $stderr] = $this->run(['--no-ansi', 'run', '--nope']);

        expect($exitCode)->toBe(64);
        expect($stderr)->toBe("greenlight: Unknown option \"--nope\". Use greenlight --help to list options.\n");
    }

    #[Test]
    public function aGlobalOptionWorksWithEachCommand(): void
    {
        [$exitCode] = $this->run(['coverage:merge', '--version']);

        expect($exitCode)
            ->because('--version is a global option')
            ->toBe(0);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function run(array $arguments): array
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stdout, $stderr));

        $exitCode = Application::forStreams($stdout, $stderr)->run($arguments, __DIR__);
        \rewind($stderr);

        return [$exitCode, (string) \stream_get_contents($stderr)];
    }
}
