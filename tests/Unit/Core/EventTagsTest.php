<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\EventTags;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\SuiteFinished;
use Greenlight\Core\Event\SuiteStarted;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\TestStarted;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Expect\Expect;

final class EventTagsTest
{
    #[Test]
    public function publishedTagsKeepTheirEventClasses(): void
    {
        Expect::that(EventTags::all())
            ->because(
                'published event tags MUST keep their machine-readable meanings',
            )
            ->toBe([
                'run-started' => RunStarted::class,
                'run-finished' => RunFinished::class,
                'suite-started' => SuiteStarted::class,
                'suite-finished' => SuiteFinished::class,
                'class-started' => TestClassStarted::class,
                'class-finished' => TestClassFinished::class,
                'test-started' => TestStarted::class,
                'test-finished' => TestFinished::class,
                'worker-spawned' => WorkerSpawned::class,
                'worker-recycled' => WorkerRecycled::class,
            ]);
    }
}
