<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\CompositeReporter;
use Greenlight\Reporting\GithubReporter;
use Greenlight\Reporting\PlainReporter;

final class CompositeReporterTest
{
    #[Test]
    public function everyReporterSeesEveryEventAndFinish(): void
    {
        $first = new RecordingReporter();
        $second = new RecordingReporter();

        CannedStream::feed(new CompositeReporter([$first, $second]));

        $expected = \count(CannedStream::events());

        Expect::that($first->eventCount)->toBe($expected)
            ->and($second->eventCount)->toBe($expected)
            ->and($first->finished)->toBeTrue()
            ->and($second->finished)->toBeTrue();
    }

    #[Test]
    public function fanOutMatchesRunningEachReporterAlone(): void
    {
        $alonePlain = new BufferOutput();
        CannedStream::feed(new PlainReporter($alonePlain));

        $aloneGithub = new BufferOutput();
        CannedStream::feed(new GithubReporter($aloneGithub));

        $compositePlain = new BufferOutput();
        $compositeGithub = new BufferOutput();
        CannedStream::feed(new CompositeReporter([
            new PlainReporter($compositePlain),
            new GithubReporter($compositeGithub),
        ]));

        Expect::that($compositePlain->buffer())->toBe($alonePlain->buffer())
            ->and($compositeGithub->buffer())->toBe($aloneGithub->buffer());
    }

    #[Test]
    public function ticksReachOnlyTickingReporters(): void
    {
        $plain = new RecordingReporter();
        $live = new RecordingTickingReporter();

        new CompositeReporter([$plain, $live])->tick(1.5);

        Expect::that($live->ticks)->toBe([1.5])
            ->and($plain->eventCount)->toBe(0);
    }
}
