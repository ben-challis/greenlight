<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

use function Greenlight\expect;

#[RequiresResource('analysis-process')]
final readonly class RectorLooseNegationTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function keepsLooseNegationsOfMixedKindsOnPhpUnit(): void
    {
        $cases = [
            'false and strpos result' => self::source("\$this->assertNotEquals(false, \\strpos('abc', 'a'));"),
            'null and empty string' => self::source("\$this->assertNotEquals(null, '');"),
            'integer and numeric string' => self::source("\$this->assertNotEquals(0, '0');"),
            'mixed array values' => self::source("\$this->assertNotEquals(['a' => 1], ['a' => '1']);"),
            'canonicalizing associative arrays' => self::source(
                "\$this->assertNotEqualsCanonicalizing(['a' => 1], ['b' => 1]);",
            ),
            'canonicalizing mixed lists' => self::source("\$this->assertNotEqualsCanonicalizing([0], ['0']);"),
        ];

        $probes = RectorProbe::convertBatch($this->tempDirectory, $cases, name: 'loose-negations');

        foreach ($probes as $caseName => $probe) {
            expect($probe->changed)->because('loose negation case: ' . $caseName)->toBeFalse();
        }
    }

    #[Test]
    public function convertsLooseNegationsOfOneKind(): void
    {
        $cases = [
            'strings' => self::source("\$this->assertNotEquals('a', 'b');"),
            'integer arrays' => self::source("\$this->assertNotEquals(['a' => 1], ['a' => 2]);"),
            'canonicalizing integer lists' => self::source("\$this->assertNotEqualsCanonicalizing([1, 2], [1, 3]);"),
        ];

        $probes = RectorProbe::convertBatch($this->tempDirectory, $cases, name: 'strict-negations');

        foreach ($probes as $caseName => $probe) {
            expect($probe->changed)->because('strict negation case: ' . $caseName)->toBeTrue();
            expect($probe->code)->because('strict negation case: ' . $caseName)->toContain('->not()->toEqual');
        }
    }

    private static function source(string $statement): string
    {
        return <<<PHP_WRAP
            <?php

            declare(strict_types=1);

            namespace App\\Tests;

            use PHPUnit\\Framework\\TestCase;

            final class ProbeTest extends TestCase
            {
                public function testValue(): void
                {
                    {$statement}
                }
            }

            PHP_WRAP;
    }
}
