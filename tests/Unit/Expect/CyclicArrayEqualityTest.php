<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\PhpSubprocess;

final class CyclicArrayEqualityTest
{
    #[Test]
    #[DataRow(['toEqual'], label: 'deep equality')]
    #[DataRow(['toEqualCanonicalizing'], label: 'canonical equality')]
    public function cyclicArraysCompareWithoutExhaustingTheProcess(string $method): void
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

            use Greenlight\Expect\Expect;

            $method = $argv[2];
            $left = [];
            $left['self'] = &$left;
            $left['value'] = 1;
            $right = ['value' => 1.0];
            $right['self'] = &$right;
            Expect::that($left)->{$method}($right);

            $right['value'] = 2;
            Expect::that($left)->not()->{$method}($right);
            Expect::that($right)->not()->{$method}($left);
            $right['value'] = 1;
            Expect::that((object) ['items' => $left])->{$method}((object) ['items' => $right]);

            $first = ['value' => 1];
            $second = ['value' => 2];
            $first['next'] = &$second;
            $second['next'] = &$first;
            $otherFirst = ['value' => 1.0];
            $otherSecond = ['value' => 2.0];
            $otherFirst['next'] = &$otherSecond;
            $otherSecond['next'] = &$otherFirst;
            Expect::that($first)->{$method}($otherFirst);

            $shared = ['value' => 1];
            $aliases = [&$shared, &$shared];
            Expect::that($aliases)->{$method}([['value' => 1], ['value' => 1]]);

            if ($method === 'toEqualCanonicalizing') {
                $left = [2, 1];
                $left[] = &$left;
                $right = [];
                $right[] = &$right;
                $right[] = 1;
                $right[] = 2;
                Expect::that($left)->toEqualCanonicalizing($right);
                Expect::that([$first, $second])->toEqualCanonicalizing([$otherSecond, $otherFirst]);
                Expect::that(array_slice($left, 0, 2))->toBe([2, 1]);
                Expect::that(array_slice($right, 1))->toBe([1, 2]);
            }

            fwrite(STDOUT, 'matched');
            PHP,
            $root . '/vendor/autoload.php',
            $method,
        ]);

        Expect::that($result->exitCode)
            ->because('cyclic array equality MUST terminate within the bounded child process')
            ->toBe(0);
        Expect::that($result->stdout)->toBe('matched');
        Expect::that($result->stderr)->toBe('');
    }
}
