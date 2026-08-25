<?php

declare(strict_types=1);

namespace Greenlight\Artifact;

/**
 * Greenlight raises this error for invalid attachment input or an attachment storage failure.
 */
final class AttachmentError extends \RuntimeException
{
    public static function unavailable(): self
    {
        return new self('Attachments are not available outside an active test attempt.');
    }

    public static function sealed(): self
    {
        return new self('Attachments cannot be added after the test attempt has finished.');
    }

    public static function invalidName(string $name): self
    {
        return new self(\sprintf('Attachment name "%s" is not a safe non-empty name.', $name));
    }

    public static function invalidMediaType(string $mediaType): self
    {
        return new self(\sprintf('Attachment media type "%s" is invalid.', $mediaType));
    }

    public static function invalidValue(string $message): self
    {
        return new self('Attachment value cannot be encoded as JSON: ' . $message . '.');
    }

    public static function source(string $path, string $reason): self
    {
        return new self(\sprintf('Attachment source "%s" %s.', $path, $reason));
    }

    public static function limit(string $message): self
    {
        return new self($message . '.');
    }

    public static function storage(string $message): self
    {
        return new self($message . '.');
    }

    /** @internal */
    public static function plugin(\Throwable $cause): self
    {
        return new self($cause->getMessage(), previous: $cause);
    }
}
