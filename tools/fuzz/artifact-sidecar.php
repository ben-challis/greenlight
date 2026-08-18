<?php

declare(strict_types=1);

namespace Greenlight\Tools\Fuzz;

use Greenlight\Core\Artifact\StagedAttachment;
use Greenlight\Core\Wire\InvalidWirePayload;

require_once __DIR__ . '/FuzzerConfiguration.php';
require_once __DIR__ . '/GuardedTarget.php';
require_once __DIR__ . '/StructuredInput.php';
require_once __DIR__ . '/../../vendor/autoload.php';

$template = StructuredInput::map(\json_decode(
    (string) \file_get_contents(__DIR__ . '/corpus/artifact-sidecar/valid-sidecar.json'),
    true,
    512,
    \JSON_THROW_ON_ERROR,
));

if ($template === null) {
    throw new \RuntimeException('The artifact-sidecar template is not a JSON object.');
}

/** @var FuzzerConfiguration $config */
$config->setAllowedExceptions([]);
$config->setMaxLen(64 * 1024);

$config->setTarget(GuardedTarget::wrap(static function (string $input) use ($template): void {
    try {
        $decoded = \str_starts_with($input, 'S')
            ? StructuredInput::mutate([$template], \substr($input, 1))
            : \json_decode($input, true, 512, \JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        return;
    }

    $payload = StructuredInput::map($decoded);

    if ($payload === null) {
        return;
    }

    try {
        $attachment = StagedAttachment::fromWire($payload);
    } catch (\Throwable $error) {
        if ($error instanceof InvalidWirePayload || $error instanceof \InvalidArgumentException) {
            return;
        }

        throw $error;
    }

    try {
        $encoded = \json_encode($attachment->toWire(), \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $roundTrip = StructuredInput::map(\json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR));
        $restored = $roundTrip === null ? null : StagedAttachment::fromWire($roundTrip);
    } catch (\Throwable $error) {
        throw new \Error('An accepted artifact sidecar did not survive the JSON round trip.', $error->getCode(), previous: $error);
    }

    if (!$restored instanceof StagedAttachment || $restored->toWire() !== $attachment->toWire()) {
        throw new \Error('The artifact-sidecar JSON round trip changed the attachment.');
    }
}));
