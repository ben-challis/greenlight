<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Terminal;
use Greenlight\Core\Test\Cleanup;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;

final readonly class TerminalTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function aNonTtyProbeDoesNotConsumeOrCloseTheStream(): void
    {
        $stream = \fopen('php://temp', 'w+');

        if ($stream === false) {
            Fail::because('Expected php://temp to open.');
        }

        $this->cleanup->defer(static fn(): bool => \fclose($stream));

        \fwrite($stream, 'sentinel');
        \rewind($stream);

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
