<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Coverage;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Coverage\CoverageWriter;
use Greenlight\Cli\Output\Console;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\Style;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\MemoryStream;

final readonly class CoverageWriterMissingResultTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    #[DataSet('requirements')]
    public function missingCoverageUsesTheConfiguredRequirement(
        CoverageConfiguration $configuration,
        bool $accepted,
        string $diagnostic,
    ): void {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stdout, $stderr));
        $out = '';
        $err = '';
        $console = new Console(
            $stdout,
            $stderr,
            static function (string $text) use (&$out): void {
                $out .= $text;
            },
            static function (string $text) use (&$err): void {
                $err .= $text;
            },
        );

        $result = new CoverageWriter($console)->write(
            $configuration,
            null,
            '/project',
            new Style(false),
        );

        Expect::that($result)->toBe($accepted);
        Expect::that($out)->because('missing coverage MUST NOT write to standard output')->toBe('');
        Expect::that($err)->toBe($diagnostic);
    }

    /** @return iterable<string, array{CoverageConfiguration, bool, non-empty-string}> */
    public static function requirements(): iterable
    {
        yield 'optional coverage' => [
            new CoverageConfiguration([], null, []),
            true,
            "No worker collected the requested coverage. Install pcov or enable Xdebug with coverage mode.\n",
        ];
        yield 'minimum percentage gate' => [
            new CoverageConfiguration([], null, [], 90.0),
            false,
            "Coverage is required, but no worker collected it. Install pcov or enable Xdebug with coverage mode.\n",
        ];
        yield 'maximum uncovered-line gate' => [
            new CoverageConfiguration([], null, [], maximumUncoveredLines: 10),
            false,
            "Coverage is required, but no worker collected it. Install pcov or enable Xdebug with coverage mode.\n",
        ];
        yield 'required driver' => [
            new CoverageConfiguration([], null, [], requireDriver: true),
            false,
            "Coverage is required, but no worker collected it. Install pcov or enable Xdebug with coverage mode.\n",
        ];
    }
}
