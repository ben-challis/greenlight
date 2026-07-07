<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Expect\Expectation;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;

/**
 * Teaches PHPStan the extension matchers your greenlight config files
 * register, with parameter signatures reflected from the matcher closures.
 * Calls like $expect->that($id)->toBeValidUuid() are then checked for name
 * typos, argument count, and argument types instead of falling through to
 * the __call fallback.
 *
 * Registered by including extension.neon and listing config files:
 *
 *     includes:
 *         - vendor/greenlight/greenlight/extension.neon
 *     parameters:
 *         greenlight:
 *             configFiles:
 *                 - greenlight.php
 *
 * All listed files contribute their matchers as one union; the same matcher
 * name with two different signatures fails the analysis run loudly. Config
 * files are loaded the same way workers load them, so plugin constructors
 * run inside the PHPStan process.
 */
final class ExpectationMethodsExtension implements MethodsClassReflectionExtension
{
    private ?MatcherMap $map = null;

    /**
     * @param list<string> $configFiles relative paths resolve against the
     *                                  directory PHPStan runs from
     */
    public function __construct(
        private readonly array $configFiles,
    ) {}

    #[\Override]
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $classReflection->getName() === Expectation::class
            && $this->map()->has($methodName);
    }

    #[\Override]
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return new ExtensionMatcherMethod(
            $classReflection,
            $methodName,
            $this->map()->parameters($methodName),
        );
    }

    private function map(): MatcherMap
    {
        return $this->map ??= MatcherMap::fromConfigFiles($this->configFiles);
    }
}
