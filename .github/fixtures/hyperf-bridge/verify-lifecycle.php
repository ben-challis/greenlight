<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';
$expected = match ($mode) {
    'worker' => [
        'containers' => 1,
        'resets' => 2,
        'disposals' => 1,
        'resetInCoroutine' => true,
        'disposeInCoroutine' => true,
    ],
    'attempt' => [
        'containers' => 2,
        'resets' => 2,
        'disposals' => 2,
        'resetInCoroutine' => true,
        'disposeInCoroutine' => true,
    ],
    default => throw new RuntimeException('The lifecycle verification mode is invalid.'),
};
$path = __DIR__ . '/runtime/lifecycle-' . $mode . '.json';
$contents = @file_get_contents($path);

if (!is_string($contents)) {
    throw new RuntimeException('The Hyperf lifecycle marker does not exist.');
}

$actual = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

if ($actual !== $expected) {
    throw new RuntimeException(sprintf(
        'The Hyperf %s lifecycle marker does not match. Expected %s, got %s.',
        $mode,
        json_encode($expected, JSON_THROW_ON_ERROR),
        json_encode($actual, JSON_THROW_ON_ERROR),
    ));
}

fwrite(STDOUT, sprintf("Verified the Hyperf %s container lifecycle.\n", $mode));
