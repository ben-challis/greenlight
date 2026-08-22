<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Event;

use Greenlight\Attribute\Test;
use Greenlight\Event\EventTags;
use Greenlight\Event\RunFinished;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestClassFinished;
use Greenlight\Event\TestClassStarted;
use Greenlight\Event\TestFinished;
use Greenlight\Event\TestStarted;
use Greenlight\Event\WorkerSpawned;
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
                'class-started' => TestClassStarted::class,
                'class-finished' => TestClassFinished::class,
                'test-started' => TestStarted::class,
                'test-finished' => TestFinished::class,
                'worker-spawned' => WorkerSpawned::class,
            ]);
    }
}
