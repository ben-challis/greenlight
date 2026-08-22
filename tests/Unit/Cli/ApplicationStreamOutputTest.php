<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Application;
use Greenlight\Core\ErrorTrap;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Reporting\PartialWriteStream;

final readonly class ApplicationStreamOutputTest
{
    private const string SCHEME = 'greenlight-application-partial-write';

    public function __construct(
        private StreamWrappers $streamWrappers,
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

        $partial = ErrorTrap::run(static fn() => \fopen(self::SCHEME . '://partial', 'wb'));
        Expect::that($partial)
            ->because('Greenlight MUST open the partial-write CLI test stream.')
            ->not()
            ->toBeFalse();
        $this->cleanup->defer(static fn(): bool => \fclose($partial));

        $other = ErrorTrap::run(static fn() => \fopen('php://memory', 'wb'));
        Expect::that($other)
            ->because('Greenlight MUST open the comparison CLI test stream.')
            ->not()
            ->toBeFalse();
        $this->cleanup->defer(static fn(): bool => \fclose($other));

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
            'Greenlight ' . Application::VERSION . "\n",
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
