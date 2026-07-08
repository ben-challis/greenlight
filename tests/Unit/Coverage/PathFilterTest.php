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
        Expect::that(PathFilter::all()->accepts('/anywhere/at/all.php'))->toBeTrue();
    }

    #[Test]
    public function acceptsFilesUnderAnIncludeDirectory(): void
    {
        $filter = new PathFilter(['/project/src', '/project/lib/']);

        Expect::that($filter->accepts('/project/src/A.php'))->toBeTrue()
            ->and($filter->accepts('/project/src/Deep/Nested/B.php'))->toBeTrue()
            ->and($filter->accepts('/project/lib/C.php'))->toBeTrue()
            ->and($filter->accepts('/project/vendor/D.php'))->toBeFalse();
    }

    #[Test]
    public function matchingIsByPathSegmentNotStringPrefix(): void
    {
        $filter = new PathFilter(['/project/src']);

        Expect::that($filter->accepts('/project/srcond/A.php'))->toBeFalse();
    }

    #[Test]
    public function emptyDirectoryEntriesAreRejected(): void
    {
        Expect::that(static fn(): PathFilter => new PathFilter(['']))
            ->toThrow(\InvalidArgumentException::class, '/non-empty paths/');
    }
}
