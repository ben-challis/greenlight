<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ClassDeclaration;
use Greenlight\Discovery\ClassFileParser;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;

final readonly class ClassFileParserAnonymousClassTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function anonymousClassesAreNotNamedDeclarations(): void
    {
        $file = $this->tempDirectory->path() . '/Declarations.php';
        \file_put_contents($file, <<<'PHP'
            <?php

            namespace Example\Tests;

            $anonymous = new class {};

            final class NamedTest {}
            PHP);

        $declarations = \array_map(
            static fn(ClassDeclaration $declaration): array => [
                $declaration->fqcn(),
                $declaration->kind,
            ],
            ClassFileParser::declarationsIn($file),
        );

        Expect::that($declarations)
            ->because('anonymous classes MUST NOT become named discovery declarations')
            ->toBe([
                ['Example\Tests\NamedTest', 'class'],
            ]);
    }
}
