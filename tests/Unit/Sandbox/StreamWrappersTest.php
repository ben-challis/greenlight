<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Sandbox;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\StreamWrapperError;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Tests\Fixture\Execution\ProcessPool\Protocol\UnselectableStream;
use Greenlight\Tests\Fixture\StreamWrapper\AutoloadableStream;

final readonly class StreamWrappersTest
{
    #[Test]
    public function registrationRejectsAnEmptyScheme(): void
    {
        Expect::that(static function (): void {
            new StreamWrappers()->register('', UnselectableStream::class);
        })->toThrow(
            \InvalidArgumentException::class,
            message: 'Stream wrapper scheme cannot be empty.',
        );
    }

    #[Test]
    public function disposalUnregistersEveryOwnedWrapper(): void
    {
        $sandbox = new StreamWrappers();
        $sandbox->register('greenlight-sandbox-one', UnselectableStream::class);
        $sandbox->register('greenlight-sandbox-two', UnselectableStream::class);

        Expect::that(\stream_get_wrappers())
            ->toContain('greenlight-sandbox-one')
            ->toContain('greenlight-sandbox-two');

        $sandbox->dispose();

        Expect::that(\stream_get_wrappers())
            ->not()->toContain('greenlight-sandbox-one')
            ->not()->toContain('greenlight-sandbox-two');
    }

    #[Test]
    public function aRegistrationFailureKeepsTheEngineDiagnostic(): void
    {
        $owner = new StreamWrappers();
        $duplicate = new StreamWrappers();
        $scheme = 'greenlight-sandbox-duplicate';
        $owner->register($scheme, UnselectableStream::class);

        try {
            Expect::that(static function () use ($duplicate, $scheme): void {
                $duplicate->register($scheme, UnselectableStream::class);
            })->because('a duplicate wrapper registration MUST identify the scheme and cause')->toThrow(
                StreamWrapperError::class,
                '/Failed to register stream wrapper "greenlight-sandbox-duplicate": .+/',
            );
        } finally {
            $owner->dispose();
        }
    }

    #[Test]
    public function anAutoloadThrowableBecomesAStreamWrapperError(): void
    {
        $cause = new \RuntimeException('The fixture autoloader failed');
        $loader = static function (string $class) use ($cause): void {
            if ($class === AutoloadableStream::class) {
                throw $cause;
            }
        };
        \spl_autoload_register($loader, prepend: true);

        try {
            Expect::that(static function (): void {
                new StreamWrappers()->register(
                    'greenlight-sandbox-autoload',
                    AutoloadableStream::class,
                );
            })
                ->because('an autoload throwable MUST not escape the stream-wrapper seam')
                ->toThrow(
                    static function (StreamWrapperError $error) use ($cause): void {
                        Expect::that($error->getPrevious())
                            ->because('the stream-wrapper error MUST preserve the autoload error')
                            ->toBe($cause);
                    },
                );
        } finally {
            \spl_autoload_unregister($loader);
        }
    }

    #[Test]
    public function disposalContinuesAfterAWrapperWasRemovedExternally(): void
    {
        $sandbox = new StreamWrappers();
        $first = 'greenlight-sandbox-first';
        $second = 'greenlight-sandbox-second';
        $sandbox->register($first, UnselectableStream::class);
        $sandbox->register($second, UnselectableStream::class);

        Expect::that(\stream_wrapper_unregister($second))
            ->because('the external cleanup MUST remove the second wrapper')
            ->toBeTrue();

        Expect::that(static function () use ($sandbox): void {
            $sandbox->dispose();
        })->because('one failed cleanup MUST not stop the remaining wrapper cleanup')->toThrow(
            StreamWrapperError::class,
            '/Failed to unregister stream wrapper "greenlight-sandbox-second": .+/',
        );
        Expect::that(\stream_get_wrappers())
            ->because('the sandbox MUST unregister wrappers after an earlier cleanup failure')
            ->not()->toContain($first);
    }
}
