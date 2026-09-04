<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Application;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Cli\Profile\EofReadFailureStream;
use Greenlight\Tests\Fixture\Cli\Profile\FailedReadStream;
use Greenlight\Tests\Support\MemoryStream;

final readonly class ProfileReportReadFailureTest
{
    public function __construct(private StreamWrappers $wrappers, private Cleanup $cleanup) {}

    #[Test]
    public function readFailureClosesTheInputAndDoesNotPrintAProfile(): void
    {
        $this->wrappers->register('greenlight-profile-read-failure', FailedReadStream::class);
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stdout, $stderr));
        $exit = Application::forStreams($stdout, $stderr)->run(
            ['profile:report', '--input=events.jsonl', '--no-ansi'],
            'greenlight-profile-read-failure://root',
        );
        \rewind($stdout);
        \rewind($stderr);

        Expect::that($exit)->toBe(1);
        Expect::that(\stream_get_contents($stdout))->toBe('');
        Expect::that((string) \stream_get_contents($stderr))->toContain('Greenlight could not read');
        Expect::that(FailedReadStream::$closed)->toBeTrue();
    }

    #[Test]
    public function readFailureAtEndOfInputDoesNotPrintAPartialProfile(): void
    {
        $this->wrappers->register('greenlight-profile-eof-failure', EofReadFailureStream::class);
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stdout, $stderr));
        $exit = Application::forStreams($stdout, $stderr)->run(
            ['profile:report', '--input=events.jsonl', '--no-ansi'],
            'greenlight-profile-eof-failure://root',
        );
        \rewind($stdout);
        \rewind($stderr);

        Expect::that($exit)->toBe(1);
        Expect::that(\stream_get_contents($stdout))->toBe('');
        Expect::that((string) \stream_get_contents($stderr))->toContain('The profile input read failed.');
        Expect::that(EofReadFailureStream::$closed)->toBeTrue();
    }
}
