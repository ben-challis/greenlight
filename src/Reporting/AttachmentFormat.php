<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Result\TestResult;

/**
 * Shared bounded text rendering for attachment metadata.
 *
 * @internal
 */
final class AttachmentFormat
{
    private const int MAX_LISTED = 10;

    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function render(TestResult $result, string $indent = '  '): string
    {
        if ($result->attachments === []) {
            return '';
        }

        $lines = [$indent . 'attachments:'];

        foreach (\array_slice($result->attachments, 0, self::MAX_LISTED) as $attachment) {
            $lines[] = \sprintf(
                '%s  %s (%s, %d bytes): %s',
                $indent,
                $attachment->name,
                $attachment->mediaType,
                $attachment->sizeBytes,
                $attachment->path,
            );
        }

        if (\count($result->attachments) > self::MAX_LISTED) {
            $lines[] = \sprintf(
                '%s  and %d more',
                $indent,
                \count($result->attachments) - self::MAX_LISTED,
            );
        }

        return \implode("\n", $lines) . "\n";
    }

    /**
     * @param list<Attachment> $attachments
     */
    public static function paths(array $attachments): string
    {
        $lines = [];

        foreach (\array_slice($attachments, 0, self::MAX_LISTED) as $attachment) {
            $lines[] = $attachment->name . ': ' . $attachment->path;
        }

        if (\count($attachments) > self::MAX_LISTED) {
            $lines[] = \sprintf('and %d more', \count($attachments) - self::MAX_LISTED);
        }

        return \implode("\n", $lines);
    }
}
