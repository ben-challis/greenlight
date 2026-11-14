<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

use function Greenlight\expect;

#[RequiresResource('analysis-process')]
final readonly class RectorStringKeyDataRowTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function keepsStringKeyedRowsOnPhpUnit(): void
    {
        $cases = [
            'TestWith row' => $this->testWithSource("['expected' => 'b', 'input' => 'a']"),
            'yielded provider row' => $this->providerSource("yield 'swap' => ['expected' => 'b', 'input' => 'a'];"),
            'returned provider row' => $this->providerSource("return [['expected' => 'b', 'input' => 'a']];"),
            'yield from provider row' => $this->providerSource("yield from ['swap' => ['expected' => 'b', 'input' => 'a']];"),
        ];

        $probes = RectorProbe::convertBatch($this->tempDirectory, $cases, name: 'string-key-rows');

        foreach ($probes as $caseName => $probe) {
            expect($probe->changed)->because('string-keyed row case: ' . $caseName)->toBeFalse();
        }
    }

    #[Test]
    public function convertsRowsWithIntegerKeysAndLabels(): void
    {
        $cases = [
            'TestWith row' => $this->testWithSource("['b', 'a']"),
            'labelled provider rows' => $this->providerSource("return ['swap' => ['b', 'a'], 'same' => [0 => 'a', 1 => 'a']];"),
        ];

        $probes = RectorProbe::convertBatch($this->tempDirectory, $cases, name: 'integer-key-rows');

        foreach ($probes as $caseName => $probe) {
            expect($probe->changed)->because('integer-keyed row case: ' . $caseName)->toBeTrue();
        }
    }

    private function testWithSource(string $row): string
    {
        return <<<PHP_WRAP
            <?php

            declare(strict_types=1);

            namespace App\\Tests;

            use PHPUnit\\Framework\\Attributes\\TestWith;
            use PHPUnit\\Framework\\TestCase;

            final class ProbeTest extends TestCase
            {
                #[TestWith({$row})]
                public function testValue(string \$input, string \$expected): void
                {
                    \$this->assertNotSame(\$input, \$expected);
                }
            }

            PHP_WRAP;
    }

    private function providerSource(string $body): string
    {
        return <<<PHP_WRAP
            <?php

            declare(strict_types=1);

            namespace App\\Tests;

            use PHPUnit\\Framework\\Attributes\\DataProvider;
            use PHPUnit\\Framework\\TestCase;

            final class ProbeTest extends TestCase
            {
                #[DataProvider('rows')]
                public function testValue(string \$input, string \$expected): void
                {
                    \$this->assertIsString(\$input . \$expected);
                }

                public static function rows(): iterable
                {
                    {$body}
                }
            }

            PHP_WRAP;
    }
}
