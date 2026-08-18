<?php

declare(strict_types=1);

namespace App;

use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;

final class GreetingAspect extends AbstractAspect
{
    public array $classes = [Greeter::class . '::greet'];

    public array $annotations = [];

    public function process(ProceedingJoinPoint $proceedingJoinPoint): string
    {
        return $proceedingJoinPoint->process() . ' through AOP';
    }
}
