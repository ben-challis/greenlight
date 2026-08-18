<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Laravel\LaravelPlugin;
use Greenlight\Symfony\SymfonyPlugin;

final class FrameworkPluginEnvironmentValidationTest
{
    /**
     * @param \Closure(): object $construct
     */
    #[Test]
    #[DataSet('frameworkPlugins')]
    public function blankEnvironmentIsRejected(\Closure $construct): void
    {
        Expect::that($construct)
            ->because('a framework plugin environment MUST identify the environment to boot')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Framework environment MUST NOT be empty.',
            );
    }

    /**
     * @return iterable<string, array{\Closure(): object}>
     */
    public static function frameworkPlugins(): iterable
    {
        yield 'Laravel empty' => [
            static fn(): LaravelPlugin => new LaravelPlugin('/unused/bootstrap.php', env: ''),
        ];

        yield 'Laravel whitespace' => [
            static fn(): LaravelPlugin => new LaravelPlugin('/unused/bootstrap.php', env: " \t"),
        ];

        yield 'Symfony empty' => [
            static fn(): SymfonyPlugin => new SymfonyPlugin('UnusedKernel', env: ''),
        ];

        yield 'Symfony whitespace' => [
            static fn(): SymfonyPlugin => new SymfonyPlugin('UnusedKernel', env: " \t"),
        ];
    }
}
