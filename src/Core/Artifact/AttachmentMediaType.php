<?php

declare(strict_types=1);

namespace Greenlight\Core\Artifact;

/**
 * Validates media types for test attachments.
 *
 * @internal
 */
final readonly class AttachmentMediaType
{
    private const string PATTERN = '~^[a-zA-Z0-9][a-zA-Z0-9!#$&^_.+-]*/[a-zA-Z0-9][a-zA-Z0-9!#$&^_.+-]*(?:\s*;\s*[^=\s]+=(?:"[^"]*"|[^;\s]+))*$~';

    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function isValid(string $mediaType): bool
    {
        return \preg_match('/[\x00-\x1F\x7F]/', $mediaType) !== 1
            && \preg_match(self::PATTERN, $mediaType) === 1;
    }
}
