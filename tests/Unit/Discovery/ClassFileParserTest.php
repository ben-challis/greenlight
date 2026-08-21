<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ClassDeclaration;
use Greenlight\Discovery\ClassFileParser;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class ClassFileParserTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function returnsOnlyNamedClassLikeDeclarations(): void
    {
        $file = $this->tempDirectory->path() . '/ClassLikeDeclarations.php';
        \file_put_contents($file, <<<'PHP'
            <?php

            namespace Example;

            interface Contract {}
            trait SharedBehavior {}
            enum State {}
            final class NamedTest {}

            $name = NamedTest::class;
            PHP);

        $declarations = \array_map(
            static fn(ClassDeclaration $declaration): array => [
                $declaration->fqcn(),
                $declaration->kind,
            ],
            ClassFileParser::declarationsIn($file),
        );

        Expect::that($declarations)
            ->because('discovery MUST return only named class-like declarations')
            ->toBe([
                ['Example\Contract', 'interface'],
                ['Example\SharedBehavior', 'trait'],
                ['Example\State', 'enum'],
                ['Example\NamedTest', 'class'],
            ]);
    }

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

    #[Test]
    public function aNativeReadThrowableBecomesADiscoveryError(): void
    {
        Expect::that(static fn(): array => ClassFileParser::declarationsIn("invalid\0Test.php"))
            ->because('a native file-read throwable MUST not escape the discovery seam')
            ->toThrow(
                static function (DiscoveryError $error): void {
                    Expect::that($error->getPrevious())
                        ->because('the discovery error MUST preserve the native file-read error')
                        ->toBeInstanceOf(\ValueError::class);
                },
            );
    }
}
