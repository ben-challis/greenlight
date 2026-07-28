<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\Message;
use Greenlight\Runner\Protocol\MessageRegistry;
use Greenlight\Runner\Protocol\Messages\Assign;
use Greenlight\Runner\Protocol\Messages\AttemptStarted;
use Greenlight\Runner\Protocol\Messages\Done;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\Messages\EventEnvelope;
use Greenlight\Runner\Protocol\Messages\Fatal;
use Greenlight\Runner\Protocol\Messages\Hello;
use Greenlight\Runner\Protocol\Messages\Recycling;

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
        yield 'recycling' => [Recycling::class, 'recycling'];
        yield 'done' => [Done::class, 'done'];
        yield 'fatal' => [Fatal::class, 'fatal'];
    }

    #[Test]
    public function envelopeVersionAndShapeRemainStable(): void
    {
        Expect::that(MessageRegistry::envelope(new Drain()))
            ->because('the published worker-protocol envelope MUST remain compatible')
            ->toBe([
                'v' => 1,
                't' => 'drain',
                'p' => [],
            ]);
    }
}
