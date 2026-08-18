<?php

declare(strict_types=1);

namespace Greenlight\Tools\Fuzz;

use Greenlight\Runner\Protocol\JsonFrameCodec;
use Greenlight\Runner\Protocol\ProtocolError;

require_once __DIR__ . '/FuzzerConfiguration.php';
require_once __DIR__ . '/GuardedTarget.php';
require_once __DIR__ . '/../../vendor/autoload.php';

/** @var FuzzerConfiguration $config */
$config->setAllowedExceptions([]);
$config->setMaxLen(64 * 1024);

$codec = new JsonFrameCodec();

$config->setTarget(GuardedTarget::wrap(static function (string $input) use ($codec): void {
    if ($input === '') {
        return;
    }

    try {
        $decoded = $codec->decode($input);
    } catch (ProtocolError) {
        return;
    }

    try {
        $body = \substr($codec->encode($decoded), 4);

        if ($body === '') {
            throw new \Error('An encoded JSON frame has no body.');
        }

        $restored = $codec->decode($body);
    } catch (\Throwable $error) {
        throw new \Error('An accepted JSON frame did not survive the codec round trip.', $error->getCode(), previous: $error);
    }

    if ($restored !== $decoded) {
        throw new \Error('The JSON frame round trip changed the decoded payload.');
    }
}));
