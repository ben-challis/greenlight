<?php

declare(strict_types=1);

namespace Greenlight\Tools\Fuzz;

use Greenlight\Runner\Protocol\FrameBuffer;
use Greenlight\Runner\Protocol\ProtocolError;

require_once __DIR__ . '/FuzzerConfiguration.php';
require_once __DIR__ . '/GuardedTarget.php';
require_once __DIR__ . '/../../vendor/autoload.php';

/** @var FuzzerConfiguration $config */
$config->setAllowedExceptions([]);
$config->setMaxLen(64 * 1024);

$config->setTarget(GuardedTarget::wrap(static function (string $input): void {
    if ($input === '') {
        return;
    }

    $mode = $input[0];
    $bytes = \substr($input, 1);

    if ($mode === 'Z' || $mode === 'O') {
        $buffer = new FrameBuffer(maxFrameBytes: 4096);
        $buffer->feed(\pack('N', $mode === 'Z' ? 0 : 4097) . $bytes);

        try {
            $buffer->next();
        } catch (ProtocolError) {
            return;
        }

        throw new \Error('FrameBuffer accepted an invalid length prefix.');
    }

    if ($mode === 'R') {
        $buffer = new FrameBuffer(maxFrameBytes: 4096);

        try {
            foreach (\str_split($bytes, 7) as $chunk) {
                $buffer->feed($chunk);

                while ($buffer->next() !== null) {
                }
            }
        } catch (ProtocolError) {
            return;
        }

        return;
    }

    $body = $bytes === '' ? '{}' : $bytes;
    $expected = ['control', $body];
    $stream = \pack('N', 7) . 'control' . \pack('N', \strlen($body)) . $body;
    $truncated = $mode === 'T';

    if ($truncated) {
        $stream = \substr($stream, 0, -1);
        $expected = ['control'];
    }

    $buffer = new FrameBuffer(maxFrameBytes: 64 * 1024);
    $received = [];
    $offset = 0;

    while ($offset < \strlen($stream)) {
        $schedule = $input[$offset % \strlen($input)];
        $length = 1 + (\ord($schedule) % 31);
        $buffer->feed(\substr($stream, $offset, $length));
        $offset += $length;

        while (($frame = $buffer->next()) !== null) {
            $received[] = $frame;
        }
    }

    if ($received !== $expected) {
        throw new \Error('FrameBuffer changed, reordered, or lost a complete frame.');
    }

    if ($buffer->hasPendingBytes() !== $truncated) {
        throw new \Error('FrameBuffer reported the wrong partial-frame state.');
    }
}));
