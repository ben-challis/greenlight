<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Application;
use Greenlight\Core\Test\Cleanup;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\StreamWrapperSandbox;
use Greenlight\Tests\Fixture\Reporting\PartialWriteStream;

final readonly class ApplicationStreamOutputTest
{
    private const string SCHEME = 'greenlight-application-partial-write';

    public function __construct(
        private StreamWrapperSandbox $streamWrappers,
        private Cleanup $cleanup,
    ) {}

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataSet('writes')]
    public function partialWritesDoNotTruncateCliOutput(
        array $arguments,
        int $expectedExit,
        string $expectedOutput,
        bool $useStderr,
    ): void {
        $this->streamWrappers->register(self::SCHEME, PartialWriteStream::class);

        $partial = \fopen(self::SCHEME . '://partial', 'wb');
        if ($partial === false) {
            Fail::because('Greenlight did not open the CLI test streams.');
        }
        $this->cleanup->defer(static function () use ($partial): void {
            \fclose($partial);
        });

        $other = \fopen('php://memory', 'wb');
        if ($other === false) {
            Fail::because('Greenlight did not open the CLI test streams.');
        }
        $this->cleanup->defer(static function () use ($other): void {
            \fclose($other);
        });

        $application = $useStderr
            ? Application::forStreams($other, $partial)
            : Application::forStreams($partial, $other);

        Expect::that($application->run($arguments, __DIR__))
            ->because('a CLI write through a partial stream MUST preserve the exit code')
            ->toBe($expectedExit);
        Expect::that(PartialWriteStream::contents())
            ->because('a short CLI stream write MUST NOT truncate output')
            ->toBe($expectedOutput);
    }

    /**
     * @return iterable<string, array{list<string>, int, string, bool}>
     */
    public static function writes(): iterable
    {
        yield 'standard output' => [
            ['--version'],
            0,
            "Greenlight dev-main\n",
            false,
        ];

        yield 'standard error' => [
            ['__worker'],
            64,
            "__worker requires <address> <workerId> <token>.\n",
            true,
        ];
    }
}
