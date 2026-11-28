<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

use function Greenlight\expect;

#[RequiresResource('analysis-process')]
final readonly class RectorExceptionConstraintOrderTest
{
    public function __construct(private TemporaryDirectory $workspace) {}

    #[Test]
    public function keepsExceptionConstraintsWithSideEffectsOnPhpUnit(): void
    {
        $cases = [
            'class assignment before the throw' => $this->source(<<<'PHP'
                $type = \LogicException::class;
                $this->expectException($type = \RuntimeException::class);
                throw new $type('boom');
                PHP),
            'pattern assignment before class selection' => $this->source(<<<'PHP'
                $pattern = '/initial/';
                $this->expectExceptionMessageMatches($pattern = '/boom/');
                $this->expectException($pattern === '/boom/' ? \RuntimeException::class : \LogicException::class);
                throw new \RuntimeException('boom');
                PHP),
        ];

        $probes = RectorProbe::convertBatch($this->workspace, $cases, name: 'exception-constraint-order');

        foreach ($probes as $caseName => $probe) {
            expect($probe->code)->because('Exception constraint case: ' . $caseName . '.')->toBe($cases[$caseName]);
        }
    }

    #[Test]
    public function convertsExceptionClassAndPatternFromLocalVariables(): void
    {
        $probe = RectorProbe::convert($this->workspace, $this->source(<<<'PHP'
            $type = \RuntimeException::class;
            $pattern = '/boom/';
            $this->expectExceptionMessageMatches($pattern);
            $this->expectException($type);
            throw new $type('boom');
            PHP), name: 'exception-constraint-variables');

        expect($probe->changed)->toBeTrue();
        $run = $probe->runConvertedTests();
        expect($run->exitCode)->toBe(0);
        expect($run->stdout)->toContain('1 test, 1 passed');
    }

    private function source(string $body): string
    {
        $indented = \str_replace("\n", "\n        ", $body);

        return <<<PHP_WRAP
            <?php

            declare(strict_types=1);

            namespace App\\Tests;

            use PHPUnit\\Framework\\TestCase;

            final class ProbeTest extends TestCase
            {
                public function testThrows(): void
                {
                    {$indented}
                }
            }

            PHP_WRAP;
    }
}
