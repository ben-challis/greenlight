<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Test\TestId;

/**
 * Contains the selected tests for one run in execution order.
 * Each test ID occurs once. Tests from each class form one consecutive block.
 */
final readonly class TestPlan
{
    /**
     * @param list<TestId> $tests
     *
     * @throws \InvalidArgumentException
     */
    private function __construct(public array $tests)
    {
        $ids = [];
        $classes = [];
        $currentClass = null;

        foreach ($tests as $test) {
            $id = (string) $test;

            if (isset($ids[$id])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Test plan ID "%s" occurs more than once.',
                    $id,
                ));
            }

            $ids[$id] = true;

            if ($test->class === $currentClass) {
                continue;
            }

            if (isset($classes[$test->class])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Test plan class "%s" occurs in more than one block.',
                    $test->class,
                ));
            }

            $classes[$test->class] = true;
            $currentClass = $test->class;
        }
    }

    /**
     * Returns a plan with replacement test selection and order.
     * Keep each test ID unique and each class in one consecutive block.
     *
     * @param list<TestId> $tests
     *
     * @throws \InvalidArgumentException if a test ID repeats or a class occurs in separate blocks
     */
    public function withTests(array $tests): self
    {
        return new self($tests);
    }

    /**
     * @internal Greenlight creates the initial plan.
     *
     * @param list<TestId> $tests
     */
    public static function create(array $tests): self
    {
        return new self($tests);
    }
}
