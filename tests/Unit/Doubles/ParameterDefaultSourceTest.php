<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\ParameterDefaultSource;
use Greenlight\Tests\Fixture\ParameterDefaultSource\Ambiguous;
use Greenlight\Tests\Fixture\ParameterDefaultSource\First;
use Greenlight\Tests\Fixture\ParameterDefaultSource\Second;
use Greenlight\Tests\Fixture\ParameterDefaultSource\Values\Other;
use Greenlight\Tests\Fixture\ParameterDefaultSource\Values\Payload;
use Greenlight\Tests\Fixture\ParameterDefaultSource\Values\ProbeAttribute;

use function Greenlight\expect;

final class ParameterDefaultSourceTest
{
    #[Test]
    public function nestedExpressionsRetainTheirSourceAndImportsWithoutConstruction(): void
    {
        require_once __DIR__ . '/../../Fixture/ParameterDefaultSource/Contexts.php';

        $source = ParameterDefaultSource::read(new \ReflectionMethod(First\Nested::class, 'run')->getParameters()[1]);

        expect($source)->toBeArray();
        expect(\implode('', \array_map(static fn(\PhpToken $token): string => $token->text, $source['tokens'])))
            ->toBe("new Value(items: ['brace' => '}', 'nested' => [new Other()]])");
        expect($source['namespace'])->toBe('Greenlight\\Tests\\Fixture\\ParameterDefaultSource\\First');
        expect($source['imports'])->toBe([
            'value' => Payload::class,
            'other' => Other::class,
            'probeattribute' => ProbeAttribute::class,
            'clock' => 'DateTimeImmutable',
        ]);
        expect($source['constants'])->toBe([
            'ALIAS' => 'Greenlight\\Tests\\Fixture\\ParameterDefaultSource\\Values\\OPTION',
            'MAXIMUM' => 'PHP_INT_MAX',
            'PHP_INT_MIN' => 'PHP_INT_MIN',
        ]);
        expect($source['trait'])->toBe('');
    }

    #[Test]
    public function nestedTraitAliasesRetainTheOriginalMethodAndTrait(): void
    {
        require_once __DIR__ . '/../../Fixture/ParameterDefaultSource/Contexts.php';

        $source = ParameterDefaultSource::read(new \ReflectionMethod(First\Child::class, 'alias')->getParameters()[0]);

        expect($source)->toBeArray();
        expect($source['method'])->toBe('original');
        expect($source['trait'])->toBe(First\Original::class);
        expect(\implode('', \array_map(static fn(\PhpToken $token): string => $token->text, $source['tokens'])))
            ->toBe('new Value()');
    }

    #[Test]
    public function methodsOnTheSameLineUseTheirOwnClassDeclaration(): void
    {
        require_once __DIR__ . '/../../Fixture/ParameterDefaultSource/Contexts.php';

        $source = ParameterDefaultSource::read(new \ReflectionMethod(First\OwnerB::class, 'run')->getParameters()[0]);

        expect($source)->toBeArray();
        expect(\implode('', \array_map(static fn(\PhpToken $token): string => $token->text, $source['tokens'])))
            ->toBe('new Other()');
    }

    #[Test]
    public function namespaceBlocksHaveSeparateImportTables(): void
    {
        require_once __DIR__ . '/../../Fixture/ParameterDefaultSource/Contexts.php';

        $source = ParameterDefaultSource::read(new \ReflectionMethod(Second\Nested::class, 'run')->getParameters()[0]);

        expect($source)->toBeArray();
        expect($source['namespace'])->toBe('Greenlight\\Tests\\Fixture\\ParameterDefaultSource\\Second');
        expect($source['imports'])->toBe(['value' => 'stdClass']);
        expect($source['constants'])->toBe([]);
    }

    #[Test]
    public function internalMethodsHaveNoSourceExpression(): void
    {
        $parameter = new \ReflectionMethod(\DateTimeImmutable::class, '__construct')->getParameters()[0];

        expect(ParameterDefaultSource::read($parameter))->toBeNull();
    }

    #[Test]
    public function sameLineTraitConflictsHaveNoUnambiguousSource(): void
    {
        require_once __DIR__ . '/../../Fixture/ParameterDefaultSource/Contexts.php';

        $parameter = new \ReflectionMethod(Ambiguous\Selected::class, 'run')->getParameters()[0];

        expect($parameter->getDefaultValue())->toBeInstanceOf(\stdClass::class);
        expect(ParameterDefaultSource::read($parameter))->toBeNull();
    }

    #[Test]
    public function classMethodsTakePriorityOverSameLineTraitMethods(): void
    {
        require_once __DIR__ . '/../../Fixture/ParameterDefaultSource/Contexts.php';

        $source = ParameterDefaultSource::read(new \ReflectionMethod(Ambiguous\OwnMethod::class, 'run')->getParameters()[0]);

        expect($source)->toBeArray();
        expect(\implode('', \array_map(static fn(\PhpToken $token): string => $token->text, $source['tokens'])))
            ->toBe('new \\DateTimeImmutable()');
    }

    #[Test]
    public function sameLineAnonymousClassesHaveNoUnambiguousSource(): void
    {
        require_once __DIR__ . '/../../Fixture/ParameterDefaultSource/Contexts.php';

        $pair = Ambiguous\anonymousPair();
        $parameter = new \ReflectionMethod($pair[0], 'run')->getParameters()[0];

        expect(ParameterDefaultSource::read($parameter))->toBeNull();
    }
}
