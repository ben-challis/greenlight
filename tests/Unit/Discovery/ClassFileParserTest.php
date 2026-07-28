<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ClassDeclaration;
use Greenlight\Discovery\ClassFileParser;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;

final readonly class ClassFileParserTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aGlobalBracketedNamespaceClearsThePreviousNamespace(): void
    {
        $file = $this->tempDirectory->path() . '/Declarations.php';
        \file_put_contents($file, <<<'PHP'
            <?php

            namespace Example\Named {
                final class NamedTest {}
            }

            namespace {
                final class GlobalTest {}
            }
            PHP);

        $declarations = \array_map(
            static fn(ClassDeclaration $declaration): array => [
                $declaration->fqcn(),
                $declaration->kind,
            ],
            ClassFileParser::declarationsIn($file),
        );

        Expect::that($declarations)
            ->because('a global bracketed namespace clears the previous namespace')
            ->toBe([
                ['Example\Named\NamedTest', 'class'],
                ['GlobalTest', 'class'],
            ]);
    }
}
