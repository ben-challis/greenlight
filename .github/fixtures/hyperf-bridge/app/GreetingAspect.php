<?php

declare(strict_types=1);

namespace App;

use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;

final class GreetingAspect extends AbstractAspect
{
    /** @var list<string> */
    // @phpstan-ignore-next-line property.phpDocType (Hyperf declares the overridden property as an untyped array.)
    public array $classes = [Greeter::class . '::greet'];

    /** @var list<class-string> */
    // @phpstan-ignore-next-line property.phpDocType (Hyperf declares the overridden property as an untyped array.)
    public array $annotations = [];

    public function process(ProceedingJoinPoint $proceedingJoinPoint): string
    {
        $result = $proceedingJoinPoint->process();

        if (!\is_string($result)) {
            throw new \RuntimeException('The Hyperf greeting aspect requires a string result.');
        }

        return $result . ' through AOP';
    }
}
