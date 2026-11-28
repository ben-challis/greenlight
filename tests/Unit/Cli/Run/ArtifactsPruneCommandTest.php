<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Run;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Run\ArtifactsPruneCommand;
use Greenlight\Plugin\CommandResult;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\MemoryStream;

use function Greenlight\expect;

final readonly class ArtifactsPruneCommandTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    #[DataRow(['0'], label: 'zero')]
    #[DataRow(['-5'], label: 'negative')]
    #[DataRow(['abc'], label: 'letters')]
    #[DataRow(['60s'], label: 'unit suffix')]
    #[DataRow(['1.5'], label: 'fraction')]
    #[DataRow([' 60'], label: 'leading space')]
    public function anAbandonedRunAgeThatIsNotAPositiveIntegerIsAUsageError(string $raw): void
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stdout, $stderr));
        $err = '';
        $console = new Console(
            $stdout,
            $stderr,
            static function (string $text): void {},
            static function (string $text) use (&$err): void {
                $err .= $text;
            },
        );

        $result = new ArtifactsPruneCommand($console)->run(
            new ParsedArguments('artifacts:prune', ['abandoned-run-age' => [$raw], 'no-ansi' => [null]]),
            $this->tempDirectory->path(),
        );

        expect($result)
            ->because('an abandoned run age MUST be a positive integer')
            ->toEqual(CommandResult::usage());
        expect($err)->toBe(\sprintf("greenlight: --abandoned-run-age requires a positive integer. Received \"%s\".\n", $raw));
    }
}
