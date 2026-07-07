<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

use Greenlight\Core\Wire\Wire;
use Greenlight\Runner\Protocol\Messages\Assign;
use Greenlight\Runner\Protocol\Messages\Done;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\Messages\EventEnvelope;
use Greenlight\Runner\Protocol\Messages\Fatal;
use Greenlight\Runner\Protocol\Messages\Hello;
use Greenlight\Runner\Protocol\Messages\Recycling;

/**
 * Encodes protocol messages as envelopes of version, type tag, and payload.
 *
 * envelope() wraps a message; open() does the reverse. Unknown versions and
 * tags are protocol errors.
 *
 * @internal
 */
final class MessageRegistry
{
    private const int VERSION = 1;

    /**
     * @var array<non-empty-string, class-string<Message>>
     */
    private const array TAGS = [
        'hello' => Hello::class,
        'assign' => Assign::class,
        'drain' => Drain::class,
        'event' => EventEnvelope::class,
        'recycling' => Recycling::class,
        'done' => Done::class,
        'fatal' => Fatal::class,
    ];

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
     * @param array<string, mixed> $envelope
     *
     * @throws ProtocolError
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
