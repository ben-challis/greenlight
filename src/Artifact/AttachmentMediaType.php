<?php

declare(strict_types=1);

namespace Greenlight\Artifact;

/**
 * Validates media types for test attachments.
 *
 * @internal
 */
final readonly class AttachmentMediaType
{
    private const string PARAMETER_TOKEN = '[a-zA-Z0-9!#$%&\'*+.^_|\x60\~-]+';

    private const string QUOTED_PARAMETER_VALUE = '"(?:[\x20-\x21\x23-\x5B\x5D-\x7E\x80-\xFF]|\\\\[\x20-\x7E\x80-\xFF])*"';

    private const string PATTERN = '~^[a-zA-Z0-9][a-zA-Z0-9!#$&^_.+-]*/[a-zA-Z0-9][a-zA-Z0-9!#$&^_.+-]*(?:\s*;\s*'
        . self::PARAMETER_TOKEN . '=(?:' . self::QUOTED_PARAMETER_VALUE . '|' . self::PARAMETER_TOKEN . '))*$~';

    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function isValid(string $mediaType): bool
    {
        return \preg_match('/[\x00-\x1F\x7F]/', $mediaType) !== 1
            && \preg_match(self::PATTERN, $mediaType) === 1;
    }
}
