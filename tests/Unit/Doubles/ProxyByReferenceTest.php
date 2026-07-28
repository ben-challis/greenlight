<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\VariadicReference;
use Greenlight\Tests\Fixture\Doubles\Wide;

final class ProxyByReferenceTest
{
    #[Test]
    public function configuredCallbacksCanChangeByReferenceArguments(): void
    {
        $doubles = new Doubles();
        $wide = $doubles->mock(Wide::class, static function (MockPlan $plan): void {
            $plan->expects('byReference')
                ->andReturnsUsing(static function (array &$items): void {
                    $items[] = 'changed';
                });
        });
        $items = ['original'];

        try {
            $wide->byReference($items);

            Expect::that($items)
                ->because('a doubled method MUST preserve by-reference argument changes')
                ->toBe(['original', 'changed']);
        } finally {
            $doubles->dispose();
        }
    }

    #[Test]
    public function configuredCallbacksCanChangeVariadicByReferenceArguments(): void
    {
        $directory = \sys_get_temp_dir() . '/greenlight-doubles-' . \bin2hex(\random_bytes(6));
        $doubles = new Doubles($directory);
        $double = $doubles->mock(VariadicReference::class, static function (MockPlan $plan): void {
            $plan->expects('change')
                ->andReturnsUsing(static function (string &$first, string &$second): void {
                    $first = 'changed-first';
                    $second = 'changed-second';
                });
        });
        $first = 'first';
        $second = 'second';

        try {
            $double->change($first, $second);

            Expect::that([$first, $second])
                ->because('a doubled method MUST preserve variadic by-reference argument changes')
                ->toBe(['changed-first', 'changed-second']);
        } finally {
            $doubles->dispose();
            $this->removeDirectory($directory);
        }
    }

    private function removeDirectory(string $directory): void
    {
        $files = \glob($directory . '/*');

        foreach ($files === false ? [] : $files as $file) {
            @\unlink($file);
        }

        @\rmdir($directory);
    }
}
