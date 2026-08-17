<?php

declare(strict_types=1);

namespace Greenlight\Tools\Fuzz;

use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Runner\Protocol\JsonFrameCodec;
use Greenlight\Runner\Protocol\MessageRegistry;
use Greenlight\Runner\Protocol\ProtocolError;

require_once __DIR__ . '/FuzzerConfiguration.php';
require_once __DIR__ . '/../../vendor/autoload.php';

/** @var FuzzerConfiguration $config */
$config->setAllowedExceptions([]);
$config->setMaxLen(64 * 1024);

$codec = new JsonFrameCodec();

$config->setTarget(static function (string $input) use ($codec): void {
    if ($input === '') {
        return;
    }

    try {
        $envelope = $codec->decode($input);
        $message = MessageRegistry::open($envelope);
    } catch (InvalidWirePayload|ProtocolError) {
        return;
    }

    try {
        $canonicalEnvelope = MessageRegistry::envelope($message);
        $body = \substr($codec->encode($canonicalEnvelope), 4);

        if ($body === '') {
            throw new \Error('An encoded protocol message has no body.');
        }

        $restored = MessageRegistry::open($codec->decode($body));
    } catch (\Throwable $error) {
        throw new \Error('An accepted protocol message did not survive the framed round trip.', $error->getCode(), previous: $error);
    }

    if ($restored::class !== $message::class || $restored->toWire() !== $message->toWire()) {
        throw new \Error('The framed round trip changed the protocol message.');
    }
});
