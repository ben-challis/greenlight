<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorArgumentOrderTest
{
    public function __construct(private TemporaryDirectory $workspace) {}

    #[Test]
    public function keepsClassesWhoseAssertionArgumentsCanAffectEachOther(): void
    {
        $assertions = [
            'post increment' => 'self::assertSame($value, $value++);',
            'assignment' => 'self::assertSame($value, $value = 2);',
            'array mutation' => 'self::assertSame($values, array_pop($values));',
            'two function calls' => 'self::assertSame(array_shift($values), array_shift($values));',
            'property and method' => 'self::assertSame($state->value, $state->next());',
            'two property reads' => 'self::assertSame($state->first, $state->second);',
            'delta mutation' => 'self::assertEqualsWithDelta($value, 1, $value = 2);',
        ];
        $cases = \array_map($this->source(...), $assertions);
        $probes = RectorProbe::convertBatch($this->workspace, $cases, name: 'assertion-order');

        foreach ($probes as $name => $probe) {
            Expect::that($probe->code)->because('argument order: ' . $name)->toBe($cases[$name]);
        }
    }

    #[Test]
    public function convertsIndependentArgumentsAndKeepsTheirResults(): void
    {
        $probe = RectorProbe::convert($this->workspace, $this->source(<<<'PHP'
            self::assertSame(1, $value++);
            self::assertSame($value, $value);
            self::assertSame([1, 2], $values);
            self::assertInstanceOf(\stdClass::class, new \stdClass());
            self::assertEqualsWithDelta(0.3, 0.1 + 0.2, 0.001);
            PHP), name: 'independent-arguments');

        Expect::that($probe->changed)->toBeTrue();
        Expect::that($probe->runConvertedTests()->exitCode)->toBe(0);
    }

    private function source(string $assertion): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace AssertionOrder;

            final class ProbeTest extends \PHPUnit\Framework\TestCase
            {
                public function testOrder(): void
                {
                    \$value = 1;
                    \$values = [1, 2];
                    {$assertion}
                }
            }

            PHP;
    }
}
