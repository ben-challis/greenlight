<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\AllowParallel;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[AllowParallel]
#[RequiresResource('analysis-process')]
final readonly class RectorSizeAttributeTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function convertsPhpUnitSizeAttributesToSelectionGroups(): void
    {
        $cases = [];
        $groups = [];

        foreach (self::sizeAttributes() as $caseName => [$attribute, $group]) {
            $cases[$caseName] = \sprintf(
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
            $groups[$caseName] = ['attribute' => $attribute, 'group' => $group];
        }

        $probes = RectorProbe::convertBatch($this->tempDirectory, $cases, name: 'size-attributes');

        foreach ($probes as $caseName => $probe) {
            $attribute = $groups[$caseName]['attribute'];
            $group = $groups[$caseName]['group'];

            Expect::that($probe->changed)
                ->because('PHPUnit size attribute case: ' . $caseName)
                ->toBeTrue();
            Expect::that($probe->code)
                ->because('converted size group case: ' . $caseName)
                ->toContain(\sprintf("#[\\Greenlight\\Attribute\\Group('%s')]", $group))
                ->not()
                ->toContain('#[' . $attribute . ']');
        }
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
