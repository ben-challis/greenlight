<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Terminal;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;

final class TerminalTest
{
    #[Test]
    public function aNonTtyProbeDoesNotConsumeOrCloseTheStream(): void
    {
        $stream = \fopen('php://temp', 'w+');

        if ($stream === false) {
            Fail::because('Expected php://temp to open.');
        }

        try {
            \fwrite($stream, 'sentinel');
            \rewind($stream);

            Expect::that(Terminal::isTty($stream))
                ->because('an in-memory stream is not a TTY')
                ->toBeFalse()
                ->and(\stream_get_contents($stream))
                ->because('the terminal probe MUST leave stream content unchanged')
                ->toBe('sentinel')
                ->and(\is_resource($stream))
                ->because('the terminal probe MUST leave the stream open')
                ->toBeTrue();
        } finally {
            \fclose($stream);
        }
    }
}
