<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Sandbox;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class TemporaryDirectoryReuseTest
{
    #[Test]
    #[DataSet('disposedRoots')]
    public function useAfterDisposalCreatesAFreshDirectory(bool $externallyRemoved): void
    {
        $directory = new TemporaryDirectory();
        $first = $directory->path();

        if ($externallyRemoved) {
            \rmdir($first);
        }

        $directory->dispose();
        $second = $directory->path();

        try {
            Expect::that($second)
                ->because('use after disposal MUST create a fresh temp directory')
                ->not()->toBe($first);
            Expect::that(\is_dir($second))
                ->toBeTrue();
        } finally {
            $directory->dispose();
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function disposedRoots(): iterable
    {
        yield 'normal disposal' => [false];

        yield 'root already removed' => [true];
    }
}
