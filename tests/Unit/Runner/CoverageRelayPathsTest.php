<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\CoverageRelayPaths;

final readonly class CoverageRelayPathsTest
{
    /** @param non-empty-string $path */
    #[Test]
    #[DataSet('reservedPaths')]
    public function reservedPathBytesRoundTripWithoutLoss(string $path): void
    {
        $encoded = CoverageRelayPaths::encode([$path]);

        Expect::that($encoded)
            ->because('the relay environment value MUST not contain reserved bytes')
            ->not()->toContain(\PATH_SEPARATOR)
            ->not()->toContain("\0");
        Expect::that(CoverageRelayPaths::decode($encoded))
            ->because('the child process MUST receive the exact include path')
            ->toBe([$path]);
    }

    #[Test]
    public function ordinaryPathsKeepTheExistingEnvironmentFormat(): void
    {
        $encoded = '/project/src' . \PATH_SEPARATOR . '/project/lib';

        Expect::that(CoverageRelayPaths::encode(['/project/src', '/project/lib']))
            ->because('ordinary relay values remain compatible')
            ->toBe($encoded);
        Expect::that(CoverageRelayPaths::decode($encoded))
            ->toBe(['/project/src', '/project/lib']);
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function reservedPaths(): iterable
    {
        yield 'null byte' => ["/project/src\0generated"];
        yield 'path separator' => ['/project/src' . \PATH_SEPARATOR . 'generated'];
        yield 'literal escape' => ['/project/%00/generated'];
    }
}
