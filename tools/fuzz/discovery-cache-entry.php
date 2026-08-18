<?php

declare(strict_types=1);

namespace Greenlight\Tools\Fuzz;

use Greenlight\Discovery\DiscoveryCacheEntry;

require_once __DIR__ . '/FuzzerConfiguration.php';
require_once __DIR__ . '/GuardedTarget.php';
require_once __DIR__ . '/StructuredInput.php';
require_once __DIR__ . '/../../vendor/autoload.php';

$template = StructuredInput::map(\json_decode(
    (string) \file_get_contents(__DIR__ . '/corpus/discovery-cache-entry/valid-entry.json'),
    true,
    512,
    \JSON_THROW_ON_ERROR,
));

if ($template === null) {
    throw new \RuntimeException('The discovery-cache template is not a JSON object.');
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

    if (!\is_array($decoded)) {
        return;
    }

    $entry = DiscoveryCacheEntry::fromDecoded($decoded);

    if (!$entry instanceof DiscoveryCacheEntry) {
        return;
    }

    try {
        $encoded = \json_encode($entry, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $roundTrip = \json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR);
        $restored = \is_array($roundTrip) ? DiscoveryCacheEntry::fromDecoded($roundTrip) : null;
    } catch (\Throwable $error) {
        throw new \Error('An accepted discovery-cache entry could not be encoded.', $error->getCode(), previous: $error);
    }

    if (!$restored instanceof DiscoveryCacheEntry || $restored->jsonSerialize() !== $entry->jsonSerialize()) {
        throw new \Error('The discovery-cache JSON round trip changed an accepted entry.');
    }

    $entry->planEntries();
}));
