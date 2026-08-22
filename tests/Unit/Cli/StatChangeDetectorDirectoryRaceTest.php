<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Tests\Fixture\Filesystem\VanishingDirectoryStream;

final readonly class StatChangeDetectorDirectoryRaceTest
{
    private const string SCHEME = 'greenlight-vanishing-directory';

    public function __construct(private StreamWrappers $streamWrappers) {}

    #[Test]
    public function aDirectoryThatVanishesDuringTheScanIsIgnored(): void
    {
        $this->streamWrappers->register(self::SCHEME, VanishingDirectoryStream::class);
        $detector = new StatChangeDetector([self::SCHEME . '://root']);

        $changed = ErrorTrap::run(static fn() => $detector->poll(), $warning);

        Expect::that($changed)
            ->because('a directory that vanishes during a scan MUST behave as a missing directory')
            ->toBe([]);
        Expect::that($warning)
            ->because('a directory race MUST NOT leak an engine diagnostic')
            ->toBeNull();
    }
}
