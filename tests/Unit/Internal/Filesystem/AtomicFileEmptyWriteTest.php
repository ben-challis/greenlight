<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Internal\Filesystem;

use Greenlight\Attribute\Test;
use Greenlight\Internal\Filesystem\AtomicFile;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class AtomicFileEmptyWriteTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function writeReplacesTheTargetWithEmptyContent(): void
    {
        $path = $this->tempDirectory->path() . '/state.bin';
        \file_put_contents($path, 'old');

        AtomicFile::write($path, '');

        expect(\file_get_contents($path))
            ->because('an empty atomic write MUST replace the target')
            ->toBe('');
        expect(\glob($path . '.tmp-*'))
            ->because('an empty atomic write MUST leave no temporary file')
            ->toBe([]);
    }
}
