<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Execution\ProcessPool\Protocol\MessageRegistry;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Assign;
use Greenlight\Execution\ProcessPool\Protocol\Messages\AttemptStarted;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Done;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Drain;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Fatal;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Hello;
use Greenlight\Expect\Expect;

final class MessageTagsTest
{
    /**
     * @param class-string<Message> $message
     * @param non-empty-string $tag
     */
    #[Test]
    #[DataSet('publishedMessageTags')]
    public function publishedMessageTagsRemainStable(string $message, string $tag): void
    {
        Expect::that($message::tag())
            ->because('published worker-protocol message tags MUST remain stable')
            ->toBe($tag);
    }

    /**
     * @return iterable<string, array{class-string<Message>, non-empty-string}>
     */
    public static function publishedMessageTags(): iterable
    {
        yield 'hello' => [Hello::class, 'hello'];
        yield 'assign' => [Assign::class, 'assign'];
        yield 'drain' => [Drain::class, 'drain'];
        yield 'event' => [EventEnvelope::class, 'event'];
        yield 'attempt started' => [AttemptStarted::class, 'attempt-started'];
        yield 'done' => [Done::class, 'done'];
        yield 'fatal' => [Fatal::class, 'fatal'];
    }

    #[Test]
    public function envelopeVersionAndShapeRemainStable(): void
    {
        Expect::that(MessageRegistry::envelope(new Drain()))
            ->because('the published worker-protocol envelope MUST remain compatible')
            ->toBe([
                'v' => 3,
                't' => 'drain',
                'p' => [],
            ]);
    }
}
