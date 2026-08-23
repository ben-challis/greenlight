<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\PhpSubprocess;
use Greenlight\Tests\Support\SourceOnlyPhp;

final readonly class LaravelFrameworkUnavailableTest
{
    #[Test]
    public function publicRequirementProbeRejectsAMissingFramework(): void
    {
        $root = \dirname(__DIR__, 3);

        $result = PhpSubprocess::run($root, SourceOnlyPhp::command(
            $root . '/src',
            <<<'PHP'
            if (class_exists(\Illuminate\Foundation\Application::class)) {
                exit(2);
            }

            try {
                \Greenlight\Laravel\LaravelFrameworkRequirement::check();
            } catch (\Greenlight\Laravel\LaravelBridgeError $error) {
                fwrite(STDOUT, $error->getMessage());
                exit(0);
            }

            exit(3);
            PHP,
        ));

        Expect::that($result->exitCode)
            ->because('the public framework probe MUST reject an installation without Laravel')
            ->toBe(0);
        Expect::that($result->stdout)
            ->because('the error MUST explain how to install the required Laravel framework')
            ->toBe(
                'The Laravel framework is not available. LaravelPlugin requires the complete '
                . 'laravel/framework 13 package. Install laravel/framework 13 before you activate the plugin.',
            );
    }
}
