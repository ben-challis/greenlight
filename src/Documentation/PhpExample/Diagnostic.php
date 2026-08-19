<?php

declare(strict_types=1);

namespace Greenlight\Documentation\PhpExample;

/**
 * Contains one tool finding at its documentation location.
 *
 * @internal
 */
final readonly class Diagnostic
{
    public function __construct(
        public string $sourcePath,
        public int $line,
        public string $tool,
        public string $message,
        public ?string $identifier = null,
    ) {}
}
