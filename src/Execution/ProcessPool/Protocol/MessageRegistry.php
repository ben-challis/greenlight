<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Assign;
use Greenlight\Execution\ProcessPool\Protocol\Messages\AttemptReady;
use Greenlight\Execution\ProcessPool\Protocol\Messages\AttemptStarted;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Bootstrap;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Done;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Drain;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Fatal;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Hello;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Ready;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * Encodes worker-protocol messages as envelopes with a version, type tag, and payload.
 *
 * envelope() puts a message in an envelope. open() gets the message from an
 * envelope. Unknown versions and tags are protocol errors.
 *
 * @internal
 */
final class MessageRegistry
{
    private const int VERSION = 4;

    /**
     * @var array<non-empty-string, class-string<Message>>
     */
    private const array TAGS = [
        'hello' => Hello::class,
        'bootstrap' => Bootstrap::class,
        'ready' => Ready::class,
        'assign' => Assign::class,
        'drain' => Drain::class,
        'event' => EventEnvelope::class,
        'attempt-started' => AttemptStarted::class,
        'attempt-ready' => AttemptReady::class,
        'done' => Done::class,
        'fatal' => Fatal::class,
    ];

    #[CoverageIgnore]
    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function envelope(Message $message): array
    {
        return [
            'v' => self::VERSION,
            't' => $message::tag(),
            'p' => $message->toWire(),
        ];
    }

    /**
     * @return array<non-empty-string, class-string<Message>>
     */
    public static function all(): array
    {
        return self::TAGS;
    }

    /**
     * @param array<string, mixed> $envelope
     *
     * @throws ProtocolError
     * @throws WireCommunicationFailed
     */
    public static function open(array $envelope): Message
    {
        $version = Wire::int($envelope, 'v');

        if ($version !== self::VERSION) {
            throw ProtocolError::unsupportedVersion($version);
        }

        $tag = Wire::nonEmptyString($envelope, 't');
        $class = self::TAGS[$tag] ?? null;

        if ($class === null) {
            throw ProtocolError::unknownType($tag);
        }

        return $class::fromWire(Wire::map($envelope, 'p'));
    }
}
