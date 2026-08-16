<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\FinalService;
use Greenlight\Tests\Fixture\Doubles\HandlerCollision;
use Greenlight\Tests\Fixture\Doubles\MixedCaseDestructor;
use Greenlight\Tests\Fixture\Doubles\MixedCaseHandlerCollision;
use Greenlight\Tests\Fixture\Doubles\MixedCaseMagicMethods;
use Greenlight\Tests\Fixture\Doubles\PlanningBoundaries;
use Greenlight\Tests\Fixture\Doubles\ReadonlyService;
use Greenlight\Tests\Fixture\Doubles\ReusableBehavior;
use Greenlight\Tests\Fixture\Doubles\Suit;

final readonly class BoundaryTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function finalClassesCannotBeDoubled(): void
    {
        Expect::that(fn(): object => $this->doubles->mock(FinalService::class))->because('final classes cannot be doubled')
            ->toThrow(DoublesError::class, '/is final.*proxy subclass.*interface/');
    }

    #[Test]
    public function readonlyClassesCannotBeDoubled(): void
    {
        Expect::that(fn(): object => $this->doubles->mock(ReadonlyService::class))->because('readonly classes cannot be doubled')
            ->toThrow(DoublesError::class, '/readonly class.*interface/');
    }

    #[Test]
    public function enumsCannotBeDoubled(): void
    {
        Expect::that(fn(): object => $this->doubles->mock(Suit::class))->because('enums cannot be doubled')
            ->toThrow(
                DoublesError::class,
                message: 'Greenlight\Tests\Fixture\Doubles\Suit is an enum. '
                    . 'Doubles does not support enums. Use an interface that the enum implements.',
            );
    }

    #[Test]
    public function traitsCannotBeDoubled(): void
    {
        Expect::that(fn(): object => $this->doubles->mock(ReusableBehavior::class))
            ->because('a trait cannot supply an object type for a generated proxy')
            ->toThrow(
                DoublesError::class,
                message: ReusableBehavior::class . ' is a trait. '
                    . 'Doubles cannot create a proxy for a trait. '
                    . 'Use a class or interface that uses it.',
            );
    }

    /**
     * @param class-string $type
     */
    #[Test]
    #[DataSet('handlerCollisions')]
    public function theProxyHandlerMethodCannotBeDeclaredByTheDoubledType(string $type): void
    {
        Expect::that(fn(): object => $this->doubles->mock($type))
            ->because('the proxy handler method cannot be declared by the doubled type')
            ->toThrow(
                DoublesError::class,
                message: $type . ' declares __greenlightAttachHandler(). '
                    . 'This method conflicts with the proxy handler method.',
            );
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function handlerCollisions(): iterable
    {
        yield 'exact case' => [HandlerCollision::class];
        yield 'mixed case' => [MixedCaseHandlerCollision::class];
    }

    #[Test]
    public function mixedCaseConstructorAndCloneMethodsAreNotIntercepted(): void
    {
        $double = $this->doubles->stub(MixedCaseMagicMethods::class);
        $reflection = new \ReflectionClass($double);
        $constructor = $reflection->getMethod('__construct');
        $clone = $reflection->getMethod('__clone');

        Expect::that($constructor->getDeclaringClass()->name)
            ->because('PHP magic method names MUST remain case-insensitive in generated proxies')
            ->toBe(MixedCaseMagicMethods::class);
        Expect::that($clone->getDeclaringClass()->name)->toBe(MixedCaseMagicMethods::class);
    }

    #[Test]
    public function aMixedCaseDestructorDoesNotCreateADuplicateProxyMethod(): void
    {
        Expect::that($this->doubles->stub(MixedCaseDestructor::class))
            ->because('a mixed-case destructor MUST produce a valid proxy class')
            ->toBeInstanceOf(MixedCaseDestructor::class);
    }

    #[Test]
    public function planningAMissingMethodIsAnAuthoringError(): void
    {
        Expect::that(fn(): object => $this->doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('subtract');
        }))->because('planning a missing method is an authoring error')->toThrow(DoublesError::class, '/has no method subtract\(\)/');
    }

    /**
     * @param non-empty-string $method
     */
    #[Test]
    #[DataSet('unplannableMethods')]
    public function planningAnUnplannableMethodIsAnAuthoringError(string $method, string $message): void
    {
        Expect::that(fn(): object => $this->doubles->mock(
            PlanningBoundaries::class,
            static function (MockPlan $plan) use ($method): void {
                $plan->expects($method);
            },
        ))
            ->because('planning an unplannable method is an authoring error')
            ->toThrow(DoublesError::class, message: $message);
    }

    /**
     * @return iterable<string, array{non-empty-string, string}>
     */
    public static function unplannableMethods(): iterable
    {
        yield 'static' => [
            'staticMethod',
            PlanningBoundaries::class . '::staticMethod() is static. Doubles cannot intercept static methods.',
        ];

        yield 'non-public' => [
            'protectedMethod',
            PlanningBoundaries::class . '::protectedMethod() is not public. Doubles cannot plan it.',
        ];

        yield 'final' => [
            'finalMethod',
            PlanningBoundaries::class . '::finalMethod() is final. Doubles cannot intercept it. Use an interface instead.',
        ];
    }
}
