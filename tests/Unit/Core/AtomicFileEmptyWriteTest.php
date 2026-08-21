<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\AtomicFile;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class AtomicFileEmptyWriteTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function writeReplacesTheTargetWithEmptyContent(): void
    {
        $path = $this->tempDirectory->path() . '/state.bin';
        \file_put_contents($path, 'old');

        AtomicFile::write($path, '');

        Expect::that(\file_get_contents($path))
            ->because('an empty atomic write MUST replace the target')
            ->toBe('');
        Expect::that(\glob($path . '.tmp-*'))
            ->because('an empty atomic write MUST leave no temporary file')
            ->toBe([]);
    }
}
