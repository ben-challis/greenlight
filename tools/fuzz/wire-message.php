<?php

declare(strict_types=1);

namespace Greenlight\Tools\Fuzz;

use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Runner\Protocol\JsonFrameCodec;
use Greenlight\Runner\Protocol\MessageRegistry;
use Greenlight\Runner\Protocol\ProtocolError;

require_once __DIR__ . '/FuzzerConfiguration.php';
require_once __DIR__ . '/GuardedTarget.php';
require_once __DIR__ . '/StructuredInput.php';
require_once __DIR__ . '/../../vendor/autoload.php';

$templates = [];
$seedPaths = \glob(__DIR__ . '/corpus/protocol-message/*.json');

if ($seedPaths === false) {
    throw new \RuntimeException('Cannot read the protocol seed corpus.');
}

foreach ($seedPaths as $seedPath) {
    try {
        $decoded = \json_decode((string) \file_get_contents($seedPath), true, 512, \JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        continue;
    }

    $envelope = StructuredInput::map($decoded);

    if ($envelope === null) {
        continue;
    }

    try {
        MessageRegistry::open($envelope);
        $templates[] = $envelope;
    } catch (\Throwable $error) {
        if (!$error instanceof InvalidWirePayload && !$error instanceof ProtocolError && !$error instanceof \InvalidArgumentException) {
            throw $error;
        }
    }
}

if ($templates === []) {
    throw new \RuntimeException('The protocol seed corpus has no valid envelopes.');
}

/** @var FuzzerConfiguration $config */
$config->setAllowedExceptions([]);
$config->setMaxLen(64 * 1024);
$codec = new JsonFrameCodec();

$config->setTarget(GuardedTarget::wrap(static function (string $input) use ($codec, $templates): void {
    $envelope = StructuredInput::mutate($templates, $input);

    try {
        $message = MessageRegistry::open($envelope);
    } catch (\Throwable $error) {
        if ($error instanceof InvalidWirePayload || $error instanceof ProtocolError || $error instanceof \InvalidArgumentException) {
            return;
        }

        throw $error;
    }

    try {
        $canonical = MessageRegistry::envelope($message);
        $body = \substr($codec->encode($canonical), 4);

        if ($body === '') {
            throw new \Error('An encoded wire message has no body.');
        }

        $restored = MessageRegistry::open($codec->decode($body));
    } catch (\Throwable $error) {
        throw new \Error('An accepted wire message did not survive the JSON round trip.', $error->getCode(), previous: $error);
    }

    if ($restored::class !== $message::class || $restored->toWire() !== $message->toWire()) {
        throw new \Error('The wire round trip changed the protocol message.');
    }
}));
