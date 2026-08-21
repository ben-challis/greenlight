<?php

declare(strict_types=1);

const EXPECTED_IDENTIFIER = 'greenlight.nativeMatcher.subjectType';
const EXPECTED_MESSAGE = 'toContain() requires a string or iterable subject. The subject type is int.';

$reportFile = $argv[1] ?? null;

if (!\is_string($reportFile)) {
    throw new \RuntimeException('Specify the PHPStan JSON report file.');
}

$contents = \file_get_contents($reportFile);

if (!\is_string($contents)) {
    throw new \RuntimeException('PHPStan JSON report is not readable.');
}

$report = \json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
$files = \is_array($report) ? ($report['files'] ?? null) : null;
$totals = \is_array($report) ? ($report['totals'] ?? null) : null;

if (!\is_array($files) || !\is_array($totals)) {
    throw new \RuntimeException('PHPStan JSON report does not contain the required result maps.');
}

if (($totals['errors'] ?? null) !== 0) {
    throw new \RuntimeException('PHPStan JSON report contains a global analysis error.');
}

foreach ($files as $file) {
    $messages = \is_array($file) ? ($file['messages'] ?? null) : null;

    if (!\is_array($messages)) {
        continue;
    }

    foreach ($messages as $message) {
        if (
            \is_array($message)
            && ($message['identifier'] ?? null) === EXPECTED_IDENTIFIER
            && ($message['message'] ?? null) === EXPECTED_MESSAGE
        ) {
            exit(0);
        }
    }
}

throw new \RuntimeException('PHPStan did not report the expected Greenlight diagnostic.');
