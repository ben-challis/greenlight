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
    public function emptyEnvironmentIsRejected(\Closure $construct): void
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
        yield 'Laravel' => [
            static fn(): LaravelPlugin => new LaravelPlugin('/unused/bootstrap.php', env: ''),
        ];

        yield 'Symfony' => [
            static fn(): SymfonyPlugin => new SymfonyPlugin('UnusedKernel', env: ''),
        ];
    }
}
