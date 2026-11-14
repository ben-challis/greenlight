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
    public function aCommandRejectsAnOptionThatItDoesNotUse(array $arguments, string $option): void
    {
        [$exitCode, $stderr] = $this->run(['--no-ansi', ...$arguments]);

        expect($exitCode)
            ->because('an option of another command is a usage error')
            ->toBe(64);
        expect($stderr)
            ->toBe(\sprintf("greenlight: Unknown option \"%s\". Use greenlight --help to list options.\n", $option));
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function optionsOfOtherCommands(): iterable
    {
        yield 'coverage:diff with workers' => [['coverage:diff', '--workers=2'], '--workers'];
        yield 'profile:report with watch' => [['profile:report', '--watch'], '--watch'];
        yield 'run with baseline' => [['run', '--baseline=a.json'], '--baseline'];
        yield 'default run with output' => [['--output=helper.php'], '--output'];
        yield 'ide-helper with reporter' => [['ide-helper', '--reporter=plain'], '--reporter'];
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
