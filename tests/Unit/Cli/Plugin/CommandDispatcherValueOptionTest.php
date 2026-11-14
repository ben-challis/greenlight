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
    #[DataSet('separateValues')]
    public function aSeparateOptionValueIsAMissingValueNotAnUnknownCommand(array $arguments, string $expected): void
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stdout, $stderr));

        $exitCode = Application::forStreams($stdout, $stderr)->run($arguments, __DIR__);
        \rewind($stderr);

        expect($exitCode)
            ->because('a missing option value is a usage error')
            ->toBe(64);
        expect(\stream_get_contents($stderr))
            ->because('the diagnostic MUST name the option and the equals form')
            ->toBe('greenlight: ' . $expected . "\n")
            ->not()->toContain('Unknown command');
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function separateValues(): iterable
    {
        yield 'workers before the default command' => [
            ['--no-ansi', '--workers', '4'],
            'Option --workers requires a value. Use --workers=<value>.',
        ];

        yield 'config before a command' => [
            ['--no-ansi', '--config', 'greenlight.php', 'list'],
            'Option --config requires a value. Use --config=<value>.',
        ];
    }
}
