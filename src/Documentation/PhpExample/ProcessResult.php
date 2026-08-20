<?php

declare(strict_types=1);

namespace Greenlight\Documentation\PhpExample;

/**
 * Contains output and status from one analysis process.
 *
 * @internal
 */
final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}
}
