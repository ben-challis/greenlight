<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\FixtureResource;

final readonly class FixtureResourceDepthTest
{
    #[Test]
    #[DataSet('containerShapes')]
    public function acceptsTheMaximumContainerDepth(string $shape): void
    {
        $resource = FixtureResource::from(self::nestedValues($shape, 16));

        Expect::that($resource)
            ->because('fixture resources MUST accept the maximum JSON container depth')
            ->toBeInstanceOf(FixtureResource::class);
    }

    #[Test]
    #[DataSet('containerShapes')]
    public function rejectsContainersBeyondTheMaximumDepth(string $shape): void
    {
        $pathSegment = $shape === 'map' ? 'nested' : '0';
        $path = 'values.root.' . \implode('.', \array_fill(0, 16, $pathSegment));

        Expect::that(static fn(): FixtureResource => FixtureResource::from(
            self::nestedValues($shape, 17),
        ))
            ->because('fixture resources MUST reject JSON containers beyond the maximum depth')
            ->toThrow(
                \InvalidArgumentException::class,
                message: \sprintf(
                    'Fixture resource "%s" exceeds the maximum nesting depth.',
                    $path,
                ),
            );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function containerShapes(): iterable
    {
        yield 'nested maps' => ['map'];
        yield 'nested lists' => ['list'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function nestedValues(string $shape, int $depth): array
    {
        $value = 'leaf';

        for ($level = 0; $level < $depth; ++$level) {
            $value = match ($shape) {
                'map' => ['nested' => $value],
                'list' => [$value],
                default => throw new \LogicException('The data set supplied an unknown fixture resource container shape.'),
            };
        }

        return ['root' => $value];
    }
}
