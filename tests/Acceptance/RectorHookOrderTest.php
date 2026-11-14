<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

use function Greenlight\expect;

#[RequiresResource('analysis-process')]
final readonly class RectorHookOrderTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function keepsClassesWithADifferentHookOrderOnPhpUnit(): void
    {
        $cases = [
            'two before hooks' => $this->source(['#[Before] public function first', '#[Before] public function second']),
            'setUp before a before hook' => $this->source(['protected function setUp', '#[Before] public function prepare']),
            'two after hooks' => $this->source(['#[After] public function first', '#[After] public function second']),
            'tearDown before an after hook' => $this->source(['protected function tearDown', '#[After] public function clean']),
        ];

        $probes = RectorProbe::convertBatch($this->tempDirectory, $cases, name: 'different-hook-order');

        foreach ($probes as $caseName => $probe) {
            expect($probe->changed)->because('hook order case: ' . $caseName)->toBeFalse();
        }
    }

    #[Test]
    public function convertsHooksThatKeepThePhpUnitOrder(): void
    {
        $probe = RectorProbe::convert(
            $this->tempDirectory,
            $this->source([
                '#[Before] public function prepare',
                'protected function setUp',
                '#[After] public function clean',
                'protected function tearDown',
            ]),
            name: 'same-hook-order',
        );

        expect($probe->changed)->toBeTrue();

        $result = $probe->runConvertedTests();

        expect($result->exitCode)->because("converted tests output:\n" . $result->output())->toBe(0);
    }

    /**
     * @param list<string> $hooks method declarations without the parameter list
     */
    private function source(array $hooks): string
    {
        $methods = '';

        foreach ($hooks as $hook) {
            $methods .= "    {$hook}(): void\n    {\n        self::\$calls[] = __FUNCTION__;\n    }\n\n";
        }

        return <<<PHP_WRAP
            <?php

            declare(strict_types=1);

            namespace App\\Tests;

            use PHPUnit\\Framework\\Attributes\\After;
            use PHPUnit\\Framework\\Attributes\\Before;
            use PHPUnit\\Framework\\TestCase;

            final class ProbeTest extends TestCase
            {
                /** @var list<string> */
                private static array \$calls = [];

            {$methods}
                public function testValue(): void
                {
                    \$this->assertSame(['prepare', 'setUp'], self::\$calls);
                }
            }

            PHP_WRAP;
    }
}
