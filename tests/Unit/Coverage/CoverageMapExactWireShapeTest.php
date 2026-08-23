<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Wire\InvalidWirePayload;

final readonly class CoverageMapExactWireShapeTest
{
    #[Test]
    public function rejectsASurplusLineSet(): void
    {
        Expect::that(static fn(): CoverageMap => CoverageMap::fromWire([
            'files' => [
                '/src/A.php' => [[1], [2], [3]],
            ],
        ]))
            ->because('each coverage file MUST contain exactly two line sets')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "files" must be a two-element list of line lists per file, got array.',
            );
    }
}
