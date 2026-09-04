<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Documentation\PhpExample;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Documentation\PhpExample\DocumentationExampleError;
use Greenlight\Expect\Expect;

final class DocumentationExampleErrorTest
{
    /** @param \Closure(): DocumentationExampleError $createError */
    #[Test]
    #[DataSet('diagnostics')]
    public function failuresReportTheirContextWithoutACause(\Closure $createError, string $expected): void
    {
        $error = $createError();

        Expect::that($error->getMessage())->toBe($expected);
        Expect::that($error->getCode())->toBe(0);
        Expect::that($error->getPrevious())->toBeNull();
    }

    /** @return iterable<string, array{\Closure(): DocumentationExampleError, string}> */
    public static function diagnostics(): iterable
    {
        yield 'unknown command' => [
            static fn() => DocumentationExampleError::unknownCommand('inspect'),
            'Unknown documentation PHP command "inspect".',
        ];
        yield 'unavailable current directory' => [
            DocumentationExampleError::currentDirectoryUnavailable(...),
            'Cannot identify the current directory.',
        ];
        yield 'unknown option' => [
            static fn() => DocumentationExampleError::unknownOption('--unknown'),
            'Unknown documentation PHP option "--unknown".',
        ];
        yield 'missing root' => [
            static fn() => DocumentationExampleError::rootNotFound('/missing/docs'),
            'Documentation PHP root "/missing/docs" does not exist.',
        ];
        yield 'source read failure' => [
            static fn() => DocumentationExampleError::sourceReadFailed('docs/source.md'),
            'Cannot read documentation file "docs/source.md".',
        ];
        yield 'source split failure' => [
            static fn() => DocumentationExampleError::sourceSplitFailed('docs/source.md'),
            'Cannot split documentation file "docs/source.md" into lines.',
        ];
        yield 'unclosed fence' => [
            static fn() => DocumentationExampleError::unclosedFence('docs/source.md', 17),
            'docs/source.md:17: Markdown fence does not have a closing fence.',
        ];
        yield 'missing metadata' => [
            static fn() => DocumentationExampleError::missingMetadata('docs/source.md', 17),
            'docs/source.md:17: PHP fence requires php-example metadata.',
        ];
        yield 'unsupported mode' => [
            static fn() => DocumentationExampleError::unsupportedMode('docs/source.md', 17, 'unknown'),
            'docs/source.md:17: Metadata mode "unknown" is not supported.',
        ];
        yield 'duplicate generated file' => [
            static fn() => DocumentationExampleError::duplicateGeneratedFile('docs/source.md', 17, 'Probe.php', 'docs/first.md:8'),
            'docs/source.md:17: Generated file "Probe.php" is already selected by docs/first.md:8.',
        ];
        yield 'inconsistent tools' => [
            static fn() => DocumentationExampleError::inconsistentTools('docs/source.md', 17, 'configuration'),
            'docs/source.md:17: All files in example "configuration" must select the same tools.',
        ];
        yield 'metadata is not an object' => [
            static fn() => DocumentationExampleError::metadataNotAnObject('docs/source.md', 17),
            'docs/source.md:17: PHP example metadata must be a JSON object.',
        ];
        yield 'invalid metadata field name' => [
            static fn() => DocumentationExampleError::invalidMetadataFieldName('docs/source.md', 17),
            'docs/source.md:17: PHP example metadata fields must have string names.',
        ];
        yield 'invalid metadata string' => [
            static fn() => DocumentationExampleError::invalidMetadataString('docs/source.md', 17, 'example'),
            'docs/source.md:17: PHP example metadata field "example" must be a non-empty string.',
        ];
        yield 'tools are not an array' => [
            static fn() => DocumentationExampleError::toolsNotAnArray('docs/source.md', 17),
            'docs/source.md:17: PHP example metadata field "tools" must be a JSON array.',
        ];
        yield 'unsupported tool' => [
            static fn() => DocumentationExampleError::unsupportedTool('docs/source.md', 17),
            'docs/source.md:17: PHP example tool must be "phpstan" or "rector".',
        ];
        yield 'unsupported metadata field' => [
            static fn() => DocumentationExampleError::unsupportedMetadataField('docs/source.md', 17, 'unknown'),
            'docs/source.md:17: PHP example metadata field "unknown" is not supported.',
        ];
        yield 'invalid example name' => [
            static fn() => DocumentationExampleError::invalidExampleName('docs/source.md', 17, 'Bad Name'),
            'docs/source.md:17: PHP example name "Bad Name" must contain lowercase letters, digits, dots, underscores, or hyphens.',
        ];
        yield 'invalid example file' => [
            static fn() => DocumentationExampleError::invalidExampleFile('docs/source.md', 17, '/Probe.php'),
            'docs/source.md:17: PHP example file "/Probe.php" must be a relative PHP path.',
        ];
        yield 'invalid example path segment' => [
            static fn() => DocumentationExampleError::invalidExamplePathSegment('docs/source.md', 17, '../Probe.php'),
            'docs/source.md:17: PHP example file "../Probe.php" contains an invalid path segment.',
        ];
        yield 'command start failure' => [
            static fn() => DocumentationExampleError::commandStartFailed('php -l Probe.php'),
            'Cannot start command "php -l Probe.php".',
        ];
        yield 'tool output read failure' => [
            DocumentationExampleError::toolOutputReadFailed(...),
            'Cannot read documentation PHP tool output.',
        ];
        yield 'invalid PHPStan files' => [
            DocumentationExampleError::invalidPhpStanFiles(...),
            'PHPStan JSON field "files" is invalid.',
        ];
        yield 'invalid PHPStan file result' => [
            DocumentationExampleError::invalidPhpStanFileResult(...),
            'PHPStan JSON file result is invalid.',
        ];
        yield 'invalid PHPStan messages' => [
            DocumentationExampleError::invalidPhpStanMessages(...),
            'PHPStan JSON messages are invalid.',
        ];
        yield 'invalid PHPStan message' => [
            DocumentationExampleError::invalidPhpStanMessage(...),
            'PHPStan JSON message is invalid.',
        ];
        yield 'PHPStan failure without output' => [
            static fn() => DocumentationExampleError::phpStanFailedWithoutDiagnostic(''),
            'PHPStan failed without a reported diagnostic.',
        ];
        yield 'PHPStan failure with output' => [
            static fn() => DocumentationExampleError::phpStanFailedWithoutDiagnostic("  Process stopped\n"),
            'PHPStan failed without a reported diagnostic: Process stopped.',
        ];
        yield 'invalid Rector fatal errors' => [
            DocumentationExampleError::invalidRectorFatalErrors(...),
            'Rector JSON field "fatal_errors" is invalid.',
        ];
        yield 'invalid Rector fatal error' => [
            DocumentationExampleError::invalidRectorFatalError(...),
            'Rector JSON fatal error is invalid.',
        ];
        yield 'invalid Rector file diffs' => [
            DocumentationExampleError::invalidRectorFileDiffs(...),
            'Rector JSON field "file_diffs" is invalid.',
        ];
        yield 'invalid Rector file diff' => [
            DocumentationExampleError::invalidRectorFileDiff(...),
            'Rector JSON file diff is invalid.',
        ];
        yield 'Rector failure without output' => [
            static fn() => DocumentationExampleError::rectorFailedWithoutChange(''),
            'Rector failed without a reported change.',
        ];
        yield 'Rector failure with output' => [
            static fn() => DocumentationExampleError::rectorFailedWithoutChange("  Process stopped\n"),
            'Rector failed without a reported change: Process stopped.',
        ];
        yield 'missing tool' => [
            static fn() => DocumentationExampleError::toolNotFound('/tools/phpstan'),
            'Documentation PHP tool "/tools/phpstan" does not exist.',
        ];
        yield 'unknown generated file' => [
            static fn() => DocumentationExampleError::unknownGeneratedFile('other/Probe.php'),
            'Tool result refers to unknown generated file "other/Probe.php".',
        ];
        yield 'tool output is not an object' => [
            static fn() => DocumentationExampleError::toolOutputNotAnObject('PHPStan'),
            'PHPStan JSON output must be an object.',
        ];
        yield 'invalid tool field name' => [
            static fn() => DocumentationExampleError::invalidToolFieldName('Rector'),
            'Rector JSON fields must have string names.',
        ];
        yield 'generated directory creation failure' => [
            static fn() => DocumentationExampleError::generatedDirectoryCreationFailed('/output/examples'),
            'Cannot create generated directory "/output/examples".',
        ];
        yield 'generated file write failure' => [
            static fn() => DocumentationExampleError::generatedFileWriteFailed('/output/examples/Probe.php'),
            'Cannot write generated file "/output/examples/Probe.php".',
        ];
        yield 'manifest write failure' => [
            DocumentationExampleError::manifestWriteFailed(...),
            'Cannot write the documentation PHP manifest.',
        ];
        yield 'generated directory publication failure' => [
            DocumentationExampleError::generatedDirectoryPublishFailed(...),
            'Cannot publish the generated documentation PHP directory.',
        ];
        yield 'unexpected removal directory' => [
            static fn() => DocumentationExampleError::unexpectedRemovalDirectory('/user/documents'),
            'Refusing to remove unexpected directory "/user/documents".',
        ];
        yield 'unknown generated entry' => [
            DocumentationExampleError::unknownGeneratedEntry(...),
            'Generated directory contains an unknown file entry.',
        ];
        yield 'generated directory removal failure' => [
            static fn() => DocumentationExampleError::generatedDirectoryRemovalFailed('/output/examples'),
            'Cannot remove generated directory "/output/examples".',
        ];
        yield 'generated file removal failure' => [
            static fn() => DocumentationExampleError::generatedFileRemovalFailed('/output/examples/Probe.php'),
            'Cannot remove generated file "/output/examples/Probe.php".',
        ];
    }

    #[Test]
    public function invalidMetadataPreservesItsSourceAndCause(): void
    {
        $previous = new \JsonException('Syntax error', \JSON_ERROR_SYNTAX);
        $error = DocumentationExampleError::invalidMetadataJson('docs/example.md', 12, $previous);

        Expect::that($error->getMessage())->toBe(
            'docs/example.md:12: PHP example metadata is not valid JSON: Syntax error.',
        );
        Expect::that($error->getCode())->toBe(\JSON_ERROR_SYNTAX);
        Expect::that($error->getPrevious())->toBe($previous);
    }

    #[Test]
    #[DataRow(['', 'PHPStan did not produce valid JSON for configuration: Syntax error.'], label: 'without output')]
    #[DataRow([" tool failed\n", 'PHPStan did not produce valid JSON for configuration: Syntax error tool failed.'], label: 'with output')]
    public function invalidToolOutputPreservesItsContextAndCause(string $stderr, string $expected): void
    {
        $previous = new \JsonException('Syntax error', \JSON_ERROR_SYNTAX);
        $error = DocumentationExampleError::invalidToolJson('PHPStan', 'configuration', $previous, $stderr);

        Expect::that($error->getMessage())->toBe($expected);
        Expect::that($error->getCode())->toBe(\JSON_ERROR_SYNTAX);
        Expect::that($error->getPrevious())->toBe($previous);
    }
}
