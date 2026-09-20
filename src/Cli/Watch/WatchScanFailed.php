<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/**
 * Indicates that a watch poll matched more files than its configured limit.
 *
 * @internal
 */
final class WatchScanFailed extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function fileLimitExceeded(int $maximumFiles): self
    {
        return new self(\sprintf(
            'Watch mode matched more files than the limit of %d. Narrow the watch paths or patterns, or increase maximumFiles().',
            $maximumFiles,
        ));
    }
}
