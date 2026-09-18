<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Tests\Support\FixturePath;

use function Greenlight\expect;

final readonly class FixturePathTest
{
    #[Test]
    public function resolvesAFileInsideTheSharedFixtureDirectory(): void
    {
        expect(FixturePath::get('DiscoveryBasic/AlphaTest.php'))
            ->because('fixture paths MUST use the shared fixture directory')
            ->toBe(\dirname(__DIR__, 2) . '/Fixture/DiscoveryBasic/AlphaTest.php');
    }

    #[Test]
    #[DataSet('unsafePaths')]
    public function rejectsPathsThatCanEscapeOrVaryByPlatform(string $relative): void
    {
        expect()->calling(static fn(): string => FixturePath::get($relative))
            ->because('fixture paths MUST stay inside the shared fixture directory')
            ->toThrow(
                \InvalidArgumentException::class,
                message: \sprintf(
                    'Fixture path "%s" must be a relative path of plain segments.',
                    $relative,
                ),
            );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafePaths(): iterable
    {
        yield 'empty' => [''];
        yield 'absolute' => ['/DiscoveryBasic'];
        yield 'backslash' => ['DiscoveryBasic\\AlphaTest.php'];
        yield 'null byte' => ["DiscoveryBasic\0AlphaTest.php"];
        yield 'empty segment' => ['DiscoveryBasic//AlphaTest.php'];
        yield 'current directory' => ['DiscoveryBasic/./AlphaTest.php'];
        yield 'traversal' => ['DiscoveryBasic/../AlphaTest.php'];
    }
}
