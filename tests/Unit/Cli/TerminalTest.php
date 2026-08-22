<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Terminal;
use Greenlight\Expect\Expect;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\MemoryStream;

final readonly class TerminalTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function aNonTtyProbeDoesNotConsumeOrCloseTheStream(): void
    {
        $stream = MemoryStream::open('sentinel');
        $this->cleanup->defer(static fn() => MemoryStream::close($stream));

        Expect::that(Terminal::isTty($stream))
            ->because('an in-memory stream is not a TTY')
            ->toBeFalse();
        Expect::that(\stream_get_contents($stream))
            ->because('the terminal probe MUST leave stream content unchanged')
            ->toBe('sentinel');
        Expect::that(\is_resource($stream))
            ->because('the terminal probe MUST leave the stream open')
            ->toBeTrue();
    }
}
