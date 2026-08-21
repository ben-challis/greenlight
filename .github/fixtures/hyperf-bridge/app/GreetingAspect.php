<?php

declare(strict_types=1);

namespace App;

use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;

final class GreetingAspect extends AbstractAspect
{
    public function __construct()
    {
        $this->classes = [Greeter::class . '::greet'];
    }

    public function process(ProceedingJoinPoint $proceedingJoinPoint): string
    {
        $result = $proceedingJoinPoint->process();

        if (!\is_string($result)) {
            throw new \RuntimeException('The Hyperf greeting aspect requires a string result.');
        }

        return $result . ' through AOP';
    }
}
