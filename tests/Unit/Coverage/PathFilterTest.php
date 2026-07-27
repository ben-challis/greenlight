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

        Expect::that($filter->accepts('/project/src/A.php'))->because('accepts files under an include directory')->toBeTrue()
            ->and($filter->accepts('/project/src/Deep/Nested/B.php'))->toBeTrue()
            ->and($filter->accepts('/project/lib/C.php'))->toBeTrue()
            ->and($filter->accepts('/project/vendor/D.php'))->toBeFalse();
    }

    #[Test]
    public function matchingIsByPathSegmentNotStringPrefix(): void
    {
        $filter = new PathFilter(['/project/src']);

        Expect::that($filter->accepts('/project/srcond/A.php'))->because('matching is by path segment not string prefix')->toBeFalse();
    }

    #[Test]
    public function emptyDirectoryEntriesAreRejected(): void
    {
        Expect::that(static fn(): PathFilter => new PathFilter(['']))->because('empty directory entries are rejected')
            ->toThrow(\InvalidArgumentException::class, '/non-empty paths/');
    }
}
