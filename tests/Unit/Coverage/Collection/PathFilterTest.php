<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Collection;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Collection\PathFilter;

use function Greenlight\expect;

final class PathFilterTest
{
    #[Test]
    public function emptyFilterAcceptsEverything(): void
    {
        expect(PathFilter::all()->accepts('/anywhere/at/all.php'))->because('empty filter accepts everything')->toBeTrue();
    }

    #[Test]
    public function acceptsFilesUnderAnIncludeDirectory(): void
    {
        $filter = new PathFilter(['/project/src', '/project/lib/']);

        expect($filter->accepts('/project/src/A.php'))->because('accepts files under an include directory')->toBeTrue();
        expect($filter->accepts('/project/src/Deep/Nested/B.php'))->toBeTrue();
        expect($filter->accepts('/project/lib/C.php'))->toBeTrue();
        expect($filter->accepts('/project/vendor/D.php'))->toBeFalse();
    }

    #[Test]
    public function matchingIsByPathSegmentNotStringPrefix(): void
    {
        $filter = new PathFilter(['/project/src']);

        expect($filter->accepts('/project/srcond/A.php'))->because('matching is by path segment not string prefix')->toBeFalse();
    }

    #[Test]
    public function filesystemRootIsAValidIncludeDirectory(): void
    {
        $filter = new PathFilter(['/']);

        expect($filter->accepts('/project/src/A.php'))
            ->because('the filesystem root MUST remain a valid coverage include directory')
            ->toBeTrue();
        expect($filter->accepts('relative.php'))
            ->toBeFalse();
    }

    #[Test]
    public function zeroStringIsAValidRelativeIncludeDirectory(): void
    {
        $filter = new PathFilter(['0']);

        expect($filter->accepts('0/Covered.php'))
            ->because('a zero-string include directory is not empty')
            ->toBeTrue();
        expect($filter->accepts('01/NotCovered.php'))
            ->because('relative include directories MUST match by path segment')
            ->toBeFalse();
    }

    #[Test]
    public function emptyDirectoryEntriesAreRejected(): void
    {
        expect()->calling(static fn(): PathFilter => new PathFilter(['']))->because('empty directory entries are rejected')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Use nonempty paths for coverage include directories.',
            );
    }
}
