<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Internal\Filesystem;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Internal\Filesystem\RelativePath;

use function Greenlight\expect;

final readonly class RelativePathTest
{
    /** @return iterable<string, array{string, bool, bool}> */
    public static function paths(): iterable
    {
        yield 'plain' => ['a/b.txt', true, true];
        yield 'empty' => ['', false, false];
        yield 'absolute' => ['/a', false, false];
        yield 'backslash' => ['a\\b', false, true];
        yield 'empty segment' => ['a//b', true, false];
        yield 'trailing separator' => ['a/', true, false];
        yield 'dot segment' => ['a/./b', true, false];
        yield 'traversal' => ['../a', true, false];
    }

    #[Test]
    #[DataSet('paths')]
    public function checksSeparateTheRootAndSegmentRules(string $path, bool $relative, bool $plain): void
    {
        expect(RelativePath::isRelative($path))->toBe($relative);
        expect(RelativePath::hasPlainSegments($path))->toBe($plain);
        expect(RelativePath::isSafe($path))
            ->because('a safe path MUST be relative and contain only plain segments')
            ->toBe($relative && $plain);
    }
}
