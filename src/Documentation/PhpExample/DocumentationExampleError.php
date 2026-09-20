<?php

declare(strict_types=1);

namespace Greenlight\Documentation\PhpExample;

/**
 * A documentation example operation cannot complete.
 *
 * @internal
 */
final class DocumentationExampleError extends \RuntimeException
{
    private function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function unknownCommand(string $command): self
    {
        return new self(\sprintf('Unknown documentation PHP command "%s".', $command));
    }

    public static function currentDirectoryUnavailable(): self
    {
        return new self('Cannot identify the current directory.');
    }

    public static function unknownOption(string $option): self
    {
        return new self(\sprintf('Unknown documentation PHP option "%s".', $option));
    }

    public static function rootNotFound(string $root): self
    {
        return new self(\sprintf('Documentation PHP root "%s" does not exist.', $root));
    }

    public static function sourceReadFailed(string $path): self
    {
        return new self(\sprintf('Cannot read documentation file "%s".', $path));
    }

    public static function sourceSplitFailed(string $path): self
    {
        return new self(\sprintf('Cannot split documentation file "%s" into lines.', $path));
    }

    public static function unclosedFence(string $path, int $line): self
    {
        return new self(\sprintf('%s:%d: Markdown fence does not have a closing fence.', $path, $line));
    }

    public static function missingMetadata(string $path, int $line): self
    {
        return new self(\sprintf('%s:%d: PHP fence requires php-example metadata.', $path, $line));
    }

    public static function unsupportedMode(string $path, int $line, string $mode): self
    {
        return new self(\sprintf('%s:%d: Metadata mode "%s" is not supported.', $path, $line, $mode));
    }

    public static function duplicateGeneratedFile(string $path, int $line, string $file, string $firstSource): self
    {
        return new self(
            \sprintf(
                '%s:%d: Generated file "%s" is already selected by %s.',
                $path,
                $line,
                $file,
                $firstSource,
            ),
        );
    }

    public static function inconsistentTools(string $path, int $line, string $example): self
    {
        return new self(
            \sprintf(
                '%s:%d: All files in example "%s" must select the same tools.',
                $path,
                $line,
                $example,
            ),
        );
    }

    public static function invalidMetadataJson(string $path, int $line, \JsonException $previous): self
    {
        return new self(
            \sprintf(
                '%s:%d: PHP example metadata is not valid JSON: %s.',
                $path,
                $line,
                $previous->getMessage(),
            ),
            $previous->getCode(),
            $previous,
        );
    }

    public static function metadataNotAnObject(string $path, int $line): self
    {
        return new self(\sprintf('%s:%d: PHP example metadata must be a JSON object.', $path, $line));
    }

    public static function invalidMetadataFieldName(string $path, int $line): self
    {
        return new self(\sprintf('%s:%d: PHP example metadata fields must have string names.', $path, $line));
    }

    public static function invalidMetadataString(string $path, int $line, string $field): self
    {
        return new self(
            \sprintf(
                '%s:%d: PHP example metadata field "%s" must be a non-empty string.',
                $path,
                $line,
                $field,
            ),
        );
    }

    public static function toolsNotAnArray(string $path, int $line): self
    {
        return new self(\sprintf('%s:%d: PHP example metadata field "tools" must be a JSON array.', $path, $line));
    }

    public static function unsupportedTool(string $path, int $line): self
    {
        return new self(\sprintf('%s:%d: PHP example tool must be "phpstan" or "rector".', $path, $line));
    }

    public static function unsupportedMetadataField(string $path, int $line, string $field): self
    {
        return new self(\sprintf('%s:%d: PHP example metadata field "%s" is not supported.', $path, $line, $field));
    }

    public static function invalidExampleName(string $path, int $line, string $example): self
    {
        return new self(
            \sprintf(
                '%s:%d: PHP example name "%s" must contain lowercase letters, digits, dots, underscores, or hyphens.',
                $path,
                $line,
                $example,
            ),
        );
    }

    public static function invalidExampleFile(string $path, int $line, string $file): self
    {
        return new self(\sprintf('%s:%d: PHP example file "%s" must be a relative PHP path.', $path, $line, $file));
    }

    public static function invalidExamplePathSegment(string $path, int $line, string $file): self
    {
        return new self(
            \sprintf(
                '%s:%d: PHP example file "%s" contains an invalid path segment.',
                $path,
                $line,
                $file,
            ),
        );
    }

    public static function commandStartFailed(string $command): self
    {
        return new self(\sprintf('Cannot start command "%s".', $command));
    }

    public static function toolOutputReadFailed(): self
    {
        return new self('Cannot read documentation PHP tool output.');
    }

    public static function invalidPhpStanFiles(): self
    {
        return new self('PHPStan JSON field "files" is invalid.');
    }

    public static function invalidPhpStanFileResult(): self
    {
        return new self('PHPStan JSON file result is invalid.');
    }

    public static function invalidPhpStanMessages(): self
    {
        return new self('PHPStan JSON messages are invalid.');
    }

    public static function invalidPhpStanMessage(): self
    {
        return new self('PHPStan JSON message is invalid.');
    }

    public static function phpStanFailedWithoutDiagnostic(string $stderr): self
    {
        return new self(
            \sprintf(
                'PHPStan failed without a reported diagnostic%s.',
                $stderr === '' ? '' : ': ' . \trim($stderr),
            ),
        );
    }

    public static function invalidRectorFatalErrors(): self
    {
        return new self('Rector JSON field "fatal_errors" is invalid.');
    }

    public static function invalidRectorFatalError(): self
    {
        return new self('Rector JSON fatal error is invalid.');
    }

    public static function invalidRectorFileDiffs(): self
    {
        return new self('Rector JSON field "file_diffs" is invalid.');
    }

    public static function invalidRectorFileDiff(): self
    {
        return new self('Rector JSON file diff is invalid.');
    }

    public static function rectorFailedWithoutChange(string $stderr): self
    {
        return new self(
            \sprintf(
                'Rector failed without a reported change%s.',
                $stderr === '' ? '' : ': ' . \trim($stderr),
            ),
        );
    }

    public static function toolNotFound(string $binary): self
    {
        return new self(\sprintf('Documentation PHP tool "%s" does not exist.', $binary));
    }

    public static function unknownGeneratedFile(string $file): self
    {
        return new self(\sprintf('Tool result refers to unknown generated file "%s".', $file));
    }

    public static function invalidToolJson(string $tool, string $example, \JsonException $previous, string $stderr): self
    {
        return new self(
            \sprintf(
                '%s did not produce valid JSON for %s: %s%s.',
                $tool,
                $example,
                $previous->getMessage(),
                $stderr === '' ? '' : ' ' . \trim($stderr),
            ),
            $previous->getCode(),
            $previous,
        );
    }

    public static function toolOutputNotAnObject(string $tool): self
    {
        return new self(\sprintf('%s JSON output must be an object.', $tool));
    }

    public static function invalidToolFieldName(string $tool): self
    {
        return new self(\sprintf('%s JSON fields must have string names.', $tool));
    }

    public static function generatedDirectoryCreationFailed(string $directory): self
    {
        return new self(\sprintf('Cannot create generated directory "%s".', $directory));
    }

    public static function generatedFileWriteFailed(string $path): self
    {
        return new self(\sprintf('Cannot write generated file "%s".', $path));
    }

    public static function manifestWriteFailed(): self
    {
        return new self('Cannot write the documentation PHP manifest.');
    }

    public static function generatedDirectoryPublishFailed(): self
    {
        return new self('Cannot publish the generated documentation PHP directory.');
    }

    public static function unexpectedRemovalDirectory(string $directory): self
    {
        return new self(\sprintf('Refusing to remove unexpected directory "%s".', $directory));
    }

    public static function unknownGeneratedEntry(): self
    {
        return new self('Generated directory contains an unknown file entry.');
    }

    public static function generatedDirectoryRemovalFailed(string $directory): self
    {
        return new self(\sprintf('Cannot remove generated directory "%s".', $directory));
    }

    public static function generatedFileRemovalFailed(string $path): self
    {
        return new self(\sprintf('Cannot remove generated file "%s".', $path));
    }
}
