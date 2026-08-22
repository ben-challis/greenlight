<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireCommunicationFailed;
use Greenlight\Runner\Protocol\Messages\Assign;
use Greenlight\Runner\Protocol\Messages\AttemptStarted;
use Greenlight\Runner\Protocol\Messages\Bootstrap;
use Greenlight\Runner\Protocol\Messages\Done;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\Messages\EventEnvelope;
use Greenlight\Runner\Protocol\Messages\Fatal;
use Greenlight\Runner\Protocol\Messages\Hello;
use Greenlight\Runner\Protocol\Messages\Ready;

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
    private const int VERSION = 3;

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
