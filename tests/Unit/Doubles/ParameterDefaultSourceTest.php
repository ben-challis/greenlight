<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\ParameterDefaultSource;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\ParameterDefaultSource\Ambiguous;
use Greenlight\Tests\Fixture\ParameterDefaultSource\First;
use Greenlight\Tests\Fixture\ParameterDefaultSource\Second;

final class ParameterDefaultSourceTest
{
    #[Test]
    public function nestedExpressionsRetainTheirSourceAndImportsWithoutConstruction(): void
    {
        require_once __DIR__ . '/../../Fixture/ParameterDefaultSource/Contexts.php';

        $source = ParameterDefaultSource::read(new \ReflectionMethod(First\Nested::class, 'run')->getParameters()[1]);

        Expect::that($source)->toBeArray();
        Expect::that(\implode('', \array_map(static fn(\PhpToken $token): string => $token->text, $source['tokens'])))
            ->toBe("new Value(items: ['brace' => '}', 'nested' => [new Other()]])");
        Expect::that($source['namespace'])->toBe('Greenlight\\Tests\\Fixture\\ParameterDefaultSource\\First');
        Expect::that($source['imports'])->toBe([
            'value' => \Greenlight\Tests\Fixture\ParameterDefaultSource\Values\Payload::class,
            'other' => \Greenlight\Tests\Fixture\ParameterDefaultSource\Values\Other::class,
            'probeattribute' => \Greenlight\Tests\Fixture\ParameterDefaultSource\Values\ProbeAttribute::class,
            'clock' => 'DateTimeImmutable',
        ]);
        Expect::that($source['constants'])->toBe([
            'ALIAS' => 'Greenlight\\Tests\\Fixture\\ParameterDefaultSource\\Values\\OPTION',
            'MAXIMUM' => 'PHP_INT_MAX',
            'PHP_INT_MIN' => 'PHP_INT_MIN',
        ]);
        Expect::that($source['trait'])->toBe('');
    }

    #[Test]
    public function nestedTraitAliasesRetainTheOriginalMethodAndTrait(): void
    {
        require_once __DIR__ . '/../../Fixture/ParameterDefaultSource/Contexts.php';

        $source = ParameterDefaultSource::read(new \ReflectionMethod(First\Child::class, 'alias')->getParameters()[0]);

        Expect::that($source)->toBeArray();
        Expect::that($source['method'])->toBe('original');
        Expect::that($source['trait'])->toBe(First\Original::class);
        Expect::that(\implode('', \array_map(static fn(\PhpToken $token): string => $token->text, $source['tokens'])))
            ->toBe('new Value()');
    }

    #[Test]
    public function methodsOnTheSameLineUseTheirOwnClassDeclaration(): void
    {
        require_once __DIR__ . '/../../Fixture/ParameterDefaultSource/Contexts.php';

        $source = ParameterDefaultSource::read(new \ReflectionMethod(First\OwnerB::class, 'run')->getParameters()[0]);

        Expect::that($source)->toBeArray();
        Expect::that(\implode('', \array_map(static fn(\PhpToken $token): string => $token->text, $source['tokens'])))
            ->toBe('new Other()');
    }

    #[Test]
    public function namespaceBlocksHaveSeparateImportTables(): void
    {
        require_once __DIR__ . '/../../Fixture/ParameterDefaultSource/Contexts.php';

        $source = ParameterDefaultSource::read(new \ReflectionMethod(Second\Nested::class, 'run')->getParameters()[0]);

        Expect::that($source)->toBeArray();
        Expect::that($source['namespace'])->toBe('Greenlight\\Tests\\Fixture\\ParameterDefaultSource\\Second');
        Expect::that($source['imports'])->toBe(['value' => 'stdClass']);
        Expect::that($source['constants'])->toBe([]);
    }

    #[Test]
    public function internalMethodsHaveNoSourceExpression(): void
    {
        $parameter = new \ReflectionMethod(\DateTimeImmutable::class, '__construct')->getParameters()[0];

        Expect::that(ParameterDefaultSource::read($parameter))->toBeNull();
    }

    #[Test]
    public function sameLineTraitConflictsHaveNoUnambiguousSource(): void
    {
        require_once __DIR__ . '/../../Fixture/ParameterDefaultSource/Contexts.php';

        $parameter = new \ReflectionMethod(Ambiguous\Selected::class, 'run')->getParameters()[0];

        Expect::that($parameter->getDefaultValue())->toBeInstanceOf(\stdClass::class);
        Expect::that(ParameterDefaultSource::read($parameter))->toBeNull();
    }

    #[Test]
    public function classMethodsTakePriorityOverSameLineTraitMethods(): void
    {
        require_once __DIR__ . '/../../Fixture/ParameterDefaultSource/Contexts.php';

        $source = ParameterDefaultSource::read(new \ReflectionMethod(Ambiguous\OwnMethod::class, 'run')->getParameters()[0]);

        Expect::that($source)->toBeArray();
        Expect::that(\implode('', \array_map(static fn(\PhpToken $token): string => $token->text, $source['tokens'])))
            ->toBe('new \\DateTimeImmutable()');
    }

    #[Test]
    public function sameLineAnonymousClassesHaveNoUnambiguousSource(): void
    {
        require_once __DIR__ . '/../../Fixture/ParameterDefaultSource/Contexts.php';

        $pair = Ambiguous\anonymousPair();
        $parameter = new \ReflectionMethod($pair[0], 'run')->getParameters()[0];

        Expect::that(ParameterDefaultSource::read($parameter))->toBeNull();
    }
}
