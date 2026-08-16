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

        Expect::that($first->eventCount)->because('every reporter sees every event and finish')->toBe($expected);
        Expect::that($second->eventCount)->toBe($expected);
        Expect::that($first->finished)->toBeTrue();
        Expect::that($second->finished)->toBeTrue();
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

        Expect::that($compositePlain->buffer())->because('fan out matches running each reporter alone')->toBe($alonePlain->buffer());
        Expect::that($compositeGithub->buffer())->toBe($aloneGithub->buffer());
    }

    #[Test]
    public function ticksReachOnlyTickingReporters(): void
    {
        $plain = new RecordingReporter();
        $live = new RecordingTickingReporter();

        new CompositeReporter([$plain, $live])->tick(1.5);

        Expect::that($live->ticks)->because('ticks reach only ticking reporters')->toBe([1.5]);
        Expect::that($plain->eventCount)->toBe(0);
    }
}
