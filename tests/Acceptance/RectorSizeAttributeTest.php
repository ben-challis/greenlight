<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorSizeAttributeTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    #[DataSet('sizeAttributes')]
    public function convertsPhpUnitSizeAttributesToSelectionGroups(string $attribute, string $group): void
    {
        $source = \sprintf(
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Attributes\%s;
            use PHPUnit\Framework\TestCase;

            #[%s]
            final class ProbeTest extends TestCase
            {
                public function testPasses(): void
                {
                    $this->assertTrue(true);
                }
            }

            PHP_WRAP,
            $attribute,
            $attribute,
        );
        $probe = RectorProbe::convert(
            $this->tempDirectory,
            $source,
            name: 'size-attribute-' . $group,
        );

        Expect::that($probe->changed)
            ->because('the PHPUnit size attribute MUST be convertible')
            ->toBeTrue()
            ->and($probe->code)
            ->because('the converted size MUST remain available for group selection')
            ->toContain(\sprintf("#[\\Greenlight\\Attribute\\Group('%s')]", $group))
            ->not()
            ->toContain('#[' . $attribute . ']');
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function sizeAttributes(): iterable
    {
        yield 'small' => ['Small', 'small'];
        yield 'medium' => ['Medium', 'medium'];
        yield 'large' => ['Large', 'large'];
    }
}
