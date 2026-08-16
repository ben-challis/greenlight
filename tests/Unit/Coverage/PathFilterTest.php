<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\PathFilter;
use Greenlight\Expect\Expect;

final class PathFilterTest
{
    #[Test]
    public function emptyFilterAcceptsEverything(): void
    {
        Expect::that(PathFilter::all()->accepts('/anywhere/at/all.php'))->because('empty filter accepts everything')->toBeTrue();
    }

    #[Test]
    public function acceptsFilesUnderAnIncludeDirectory(): void
    {
        $filter = new PathFilter(['/project/src', '/project/lib/']);

        Expect::that($filter->accepts('/project/src/A.php'))->because('accepts files under an include directory')->toBeTrue();
        Expect::that($filter->accepts('/project/src/Deep/Nested/B.php'))->toBeTrue();
        Expect::that($filter->accepts('/project/lib/C.php'))->toBeTrue();
        Expect::that($filter->accepts('/project/vendor/D.php'))->toBeFalse();
    }

    #[Test]
    public function matchingIsByPathSegmentNotStringPrefix(): void
    {
        $filter = new PathFilter(['/project/src']);

        Expect::that($filter->accepts('/project/srcond/A.php'))->because('matching is by path segment not string prefix')->toBeFalse();
    }

    #[Test]
    public function filesystemRootIsAValidIncludeDirectory(): void
    {
        $filter = new PathFilter(['/']);

        Expect::that($filter->accepts('/project/src/A.php'))
            ->because('the filesystem root MUST remain a valid coverage include directory')
            ->toBeTrue();
        Expect::that($filter->accepts('relative.php'))
            ->toBeFalse();
    }

    #[Test]
    public function zeroStringIsAValidRelativeIncludeDirectory(): void
    {
        $filter = new PathFilter(['0']);

        Expect::that($filter->accepts('0/Covered.php'))
            ->because('a zero-string include directory is not empty')
            ->toBeTrue();
        Expect::that($filter->accepts('01/NotCovered.php'))
            ->because('relative include directories MUST match by path segment')
            ->toBeFalse();
    }

    #[Test]
    public function emptyDirectoryEntriesAreRejected(): void
    {
        Expect::that(static fn(): PathFilter => new PathFilter(['']))->because('empty directory entries are rejected')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Use nonempty paths for coverage include directories.',
            );
    }
}
