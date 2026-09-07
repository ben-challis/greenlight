<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use function Greenlight\expect;
use Greenlight\Tests\Support\PhpSubprocess;

final class CyclicArrayEqualityTest
{
    #[Test]
    #[DataRow(['toEqual'], label: 'deep equality')]
    #[DataRow(['toEqualCanonicalizing'], label: 'canonical equality')]
    public function cyclicArraysFailExplicitlyWithoutExhaustingTheProcess(string $method): void
    {
        $root = \dirname(__DIR__, 3);
        $result = PhpSubprocess::run($root, [
            '-n',
            '-d',
            'memory_limit=32M',
            '-d',
            'max_execution_time=2',
            '-r',
            <<<'PHP'
            require $argv[1];

            use function Greenlight\expect;

            $method = $argv[2];
            $reject = static function ($left, $right) use ($method): void {
                expect()->calling(static fn() => expect($left)->{$method}($right))
                    ->toThrow(InvalidArgumentException::class, message:
                        'Equality matchers do not support cyclic arrays. Compare selected acyclic values instead.');
            };
            $factories = [
                static function (): array {
                    $left = [];
                    $left['self'] = &$left;
                    $left['value'] = 1;
                    $right = ['value' => 1];
                    $right['self'] = &$right;
                    return [$left, $right];
                },
                static function (): array {
                    $left = [];
                    $left['next'] = ['next' => &$left];
                    $right = [];
                    $right['next'] = &$target;
                    $target = ['next' => $right];
                    return [$left, $right];
                },
                static function (): array {
                    $first = [];
                    $second = [];
                    $first[] = &$second;
                    $first[] = [1];
                    $second[] = &$first;
                    $second[] = [2];
                    return [[$first, $second], [$second, $first]];
                },
            ];

            foreach ($factories as $make) {
                [$left, $right] = $make();
                $reject($left, $right);
                $reject($right, $left);
                $reject((object) ['items' => $left], (object) ['items' => $right]);
                if ($method === 'toEqualCanonicalizing') {
                    $object = (object) ['items' => $left];
                    $reject([$object], [$object]);
                }
            }

            $shared = [2, 1];
            $aliases = [&$shared, &$shared];
            expect($aliases)->{$method}([[2, 1], [2, 1]]);
            expect($shared)->toBe([2, 1]);

            $deep = ['leaf'];
            for ($depth = 0; $depth < 100; ++$depth) {
                $deep = [$deep];
            }
            expect($deep)->{$method}($deep);

            $leftObject = new stdClass();
            $leftObject->self = $leftObject;
            $rightObject = new stdClass();
            $rightObject->self = $rightObject;
            expect([$leftObject])->{$method}([$rightObject]);

            $mixedGraph = static function (): object {
                $array = [];
                $node = new stdClass();
                $array[] = $node;
                $node->items = [&$array];
                return (object) ['items' => [&$array]];
            };
            expect([$mixedGraph()])->{$method}([$mixedGraph()]);

            $object = new class implements Countable {
                public function count(): int { throw new LogicException('Do not invoke user code.'); }
                public function __serialize(): array { throw new LogicException('Do not invoke user code.'); }
            };
            expect([$object])->{$method}([$object]);

            fwrite(STDOUT, 'matched');
            PHP,
            $root . '/vendor/autoload.php',
            $method,
        ]);

        expect($result->exitCode)
            ->because('cyclic array diagnostics MUST terminate within the bounded child process')
            ->toBe(0);
        expect($result->stdout)->toBe('matched');
        expect($result->stderr)->toBe('');
    }
}
