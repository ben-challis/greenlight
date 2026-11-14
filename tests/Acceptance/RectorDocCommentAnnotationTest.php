<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

use function Greenlight\expect;

#[RequiresResource('analysis-process')]
final readonly class RectorDocCommentAnnotationTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function keepsClassesWithPhpUnitAnnotationsOnPhpUnit(): void
    {
        $cases = [];

        foreach (['test', 'before', 'after', 'dataProvider values', 'depends testOther', 'group slow', 'requires PHP 8.4', 'doesNotPerformAssertions', 'covers \App\Price', 'coversNothing', 'runInSeparateProcess'] as $tag) {
            $cases['method @' . $tag] = self::source("/**\n         * @{$tag}\n         */", '');
        }

        $cases['class @group'] = self::source('', "/**\n * @group slow\n */");
        $cases['class @coversDefaultClass'] = self::source('', '/** @coversDefaultClass \App\Price */');

        $probes = RectorProbe::convertBatch($this->tempDirectory, $cases, name: 'doc-comment-annotations');

        foreach ($probes as $caseName => $probe) {
            expect($probe->changed)->because('annotation case: ' . $caseName)->toBeFalse();
        }
    }

    #[Test]
    public function convertsClassesWithOtherDocComments(): void
    {
        $probe = RectorProbe::convert(
            $this->tempDirectory,
            self::source("/**\n         * Mail admin@test.example about @testing.\n         */", '/** @codeCoverageIgnore */'),
            name: 'plain-doc-comments',
        );

        expect($probe->changed)->toBeTrue();
    }

    private static function source(string $methodDocComment, string $classDocComment): string
    {
        return <<<PHP_WRAP
            <?php

            declare(strict_types=1);

            namespace App\\Tests;

            use PHPUnit\\Framework\\TestCase;

            {$classDocComment}
            final class ProbeTest extends TestCase
            {
                {$methodDocComment}
                public function itRuns(): void
                {
                    \$this->assertSame(1, 1);
                }

                public function testOther(): void
                {
                    \$this->assertSame(1, 1);
                }

                public static function values(): iterable
                {
                    yield [1];
                }
            }

            PHP_WRAP;
    }
}
