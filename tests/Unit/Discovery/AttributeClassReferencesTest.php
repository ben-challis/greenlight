<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\AttributeClassReferences;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class AttributeClassReferencesTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function resolvesClassNamesInAttributeConstantExpressions(): void
    {
        $file = $this->tempDirectory->subdirectory('references') . '/ReferencesTest.php';
        \file_put_contents($file, <<<'PHP'
            <?php

            namespace App\Tests;

            use App\Support\Cases;
            use App\Support\Other as Alias;
            use App\Grouped\{First, Second as Renamed};
            use function App\helper;
            use const App\LIMIT;

            #[Group(Cases::GROUP)]
            final class ReferencesTest
            {
                use SomeTrait;

                #[Test]
                #[Group(Alias::NAME), Timeout(First::SECONDS)]
                #[SkipUnless('x', [Renamed::A, Local::B])]
                #[Group(\Root\Constants::X), Group(namespace\Relative::Y), Group(Alias\Nested::Z)]
                #[Group(self::OWN), Group(static::LATE), Group(parent::BASE)]
                public function runs(): void
                {
                    $value = NotInAttribute::VALUE;
                }
            }
            PHP);

        expect(AttributeClassReferences::in($file))->toBe([
            'App\Support\Cases',
            'App\Support\Other',
            'App\Grouped\First',
            'App\Grouped\Second',
            'App\Tests\Local',
            'Root\Constants',
            'App\Tests\Relative',
            'App\Support\Other\Nested',
        ]);
    }

    #[Test]
    public function returnsNothingForAMissingFile(): void
    {
        expect(AttributeClassReferences::in($this->tempDirectory->path() . '/MissingTest.php'))->toBe([]);
    }
}
