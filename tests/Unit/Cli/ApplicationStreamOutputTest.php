<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Application;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\StreamWrapperSandbox;
use Greenlight\Tests\Fixture\Reporting\PartialWriteStream;

final readonly class ApplicationStreamOutputTest
{
    private const string SCHEME = 'greenlight-application-partial-write';

    public function __construct(private StreamWrapperSandbox $streamWrappers) {}

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
        $other = \fopen('php://memory', 'wb');

        if ($partial === false || $other === false) {
            if (\is_resource($partial)) {
                \fclose($partial);
            }

            Fail::because('Greenlight did not open the CLI test streams.');
        }

        try {
            $application = $useStderr
                ? Application::forStreams($other, $partial)
                : Application::forStreams($partial, $other);

            Expect::that($application->run($arguments, __DIR__))
                ->because('a CLI write through a partial stream MUST preserve the exit code')
                ->toBe($expectedExit);
            Expect::that(PartialWriteStream::contents())
                ->because('a short CLI stream write MUST NOT truncate output')
                ->toBe($expectedOutput);
        } finally {
            \fclose($partial);
            \fclose($other);
        }
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
