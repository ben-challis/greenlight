<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Plugin;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Application;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\MemoryStream;

use function Greenlight\expect;

final readonly class CommandDispatcherValueOptionTest
{
    public function __construct(private Cleanup $cleanup) {}

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataSet('spaceSeparatedValues')]
    public function aRequiredValueCanBeTheNextWord(array $arguments): void
    {
        [$exitCode, $stdout, $stderr] = $this->run($arguments);

        expect($exitCode)
            ->because('the word after a required-value option is its value, not a command')
            ->toBe(0);
        expect($stderr)->toBe('');
        expect($stdout)->toContain('workers: 3');
    }

    /** @return iterable<string, array{list<string>}> */
    public static function spaceSeparatedValues(): iterable
    {
        yield 'before the default command' => [['--no-ansi', '--workers', '3', '--dry-run']];
        yield 'before an explicit command' => [['--no-ansi', '--workers', '3', 'run', '--dry-run']];
        yield 'after the command' => [['run', '--no-ansi', '--workers', '3', '--dry-run']];
        yield 'equals form' => [['run', '--no-ansi', '--workers=3', '--dry-run']];
    }

    #[Test]
    public function aRequiredValueOptionAtTheEndReportsTheMissingValue(): void
    {
        [$exitCode, , $stderr] = $this->run(['run', '--no-ansi', '--workers']);

        expect($exitCode)->toBe(64);
        expect($stderr)->toBe("greenlight: Option --workers requires a value.\n");
    }

    #[Test]
    public function anOptionalValueKeepsTheEqualsFormOnly(): void
    {
        [$exitCode, , $stderr] = $this->run(['--no-ansi', '--bail', '3']);

        expect($exitCode)
            ->because('the word after an optional-value option is the command word')
            ->toBe(64);
        expect($stderr)->toContain("Unknown command '3'");
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function run(array $arguments): array
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stdout, $stderr));

        $exitCode = Application::forStreams($stdout, $stderr)->run($arguments, \dirname(__DIR__, 4));
        \rewind($stdout);
        \rewind($stderr);

        return [$exitCode, (string) \stream_get_contents($stdout), (string) \stream_get_contents($stderr)];
    }
}
