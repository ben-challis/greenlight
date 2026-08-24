<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Coverage\CoveragePluginRuntime;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\CoverageMapTransformer;
use Greenlight\Plugin\Prioritized;

final readonly class CoveragePluginRuntimeTest
{
    #[Test]
    public function transformersUseStablePluginPriorityOrder(): void
    {
        /** @var \ArrayObject<int, string> $events */
        $events = new \ArrayObject();
        $runtime = CoveragePluginRuntime::fromPlugins([
            new RecordingCoverageTransformer($events, 'late', 10),
            new RecordingCoverageTransformer($events, 'default', 0),
            new RecordingCoverageTransformer($events, 'same-priority', 0),
            new RecordingCoverageTransformer($events, 'early', -10),
        ]);

        $coverage = $runtime->transform(new CoverageMap([
            new FileCoverage('/missing/Probe.php', [1], []),
        ]));

        Expect::that($events->getArrayCopy())->toBe([
            'early',
            'default',
            'same-priority',
            'late',
        ]);
        Expect::that($coverage->files())->toHaveCount(1);
    }

    #[Test]
    public function transformerFailuresKeepThePluginAndCause(): void
    {
        $failure = new \RuntimeException('Coverage transform exploded');
        $runtime = CoveragePluginRuntime::fromPlugins([
            new FailingCoverageTransformer($failure),
        ]);

        Expect::that(static fn(): CoverageMap => $runtime->transform(CoverageMap::empty()))
            ->toThrow(static function (CoverageError $error) use ($failure): void {
                Expect::that($error->getMessage())->toBe(
                    'Coverage plugin "Greenlight\\Tests\\Unit\\Cli\\Coverage\\FailingCoverageTransformer" caused an error during transformCoverageMap: Coverage transform exploded',
                );
                Expect::that($error->getPrevious())->toBe($failure);
            });
    }
}

final readonly class RecordingCoverageTransformer implements CoverageMapTransformer, Fake, Prioritized
{
    /** @param \ArrayObject<int, string> $events */
    public function __construct(
        private \ArrayObject $events,
        private string $name,
        private int $priorityValue,
    ) {}

    #[\Override]
    public function priority(): int
    {
        return $this->priorityValue;
    }

    #[\Override]
    public function transformCoverageMap(CoverageMap $coverage): CoverageMap
    {
        $this->events->append($this->name);

        return $coverage;
    }
}

final readonly class FailingCoverageTransformer implements CoverageMapTransformer, Fake
{
    public function __construct(private \RuntimeException $failure) {}

    #[\Override]
    public function transformCoverageMap(CoverageMap $coverage): CoverageMap
    {
        throw $this->failure;
    }
}
