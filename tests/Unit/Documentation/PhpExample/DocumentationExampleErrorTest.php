<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Documentation\PhpExample;

use Greenlight\Attribute\Test;
use Greenlight\Documentation\PhpExample\DocumentationExampleError;
use Greenlight\Expect\Expect;

final class DocumentationExampleErrorTest
{
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
    public function invalidToolOutputPreservesItsContextAndCause(): void
    {
        $previous = new \JsonException('Syntax error', \JSON_ERROR_SYNTAX);
        $error = DocumentationExampleError::invalidToolJson('PHPStan', 'configuration', $previous, " tool failed\n");

        Expect::that($error->getMessage())->toBe(
            'PHPStan did not produce valid JSON for configuration: Syntax error tool failed.',
        );
        Expect::that($error->getCode())->toBe(\JSON_ERROR_SYNTAX);
        Expect::that($error->getPrevious())->toBe($previous);
    }
}
