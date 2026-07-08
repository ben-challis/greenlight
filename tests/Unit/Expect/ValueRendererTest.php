<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ValueRenderer;

final class ValueRendererTest
{
    #[Test]
    public function rendersScalarsAndNull(): void
    {
        $renderer = new ValueRenderer();

        Expect::that($renderer->render(null))->toBe('null');
        Expect::that($renderer->render(true))->toBe('true');
        Expect::that($renderer->render(false))->toBe('false');
        Expect::that($renderer->render(42))->toBe('42');
        Expect::that($renderer->render(-7))->toBe('-7');
        Expect::that($renderer->render(1.5))->toBe('1.5');
        Expect::that($renderer->render(1.0))->toBe('1.0');
        Expect::that($renderer->render(\NAN))->toBe('NAN');
        Expect::that($renderer->render(\INF))->toBe('INF');
        Expect::that($renderer->render('abc'))->toBe("'abc'");
    }

    #[Test]
    public function escapesControlCharactersInStrings(): void
    {
        Expect::that(new ValueRenderer()->render("a\nb\tc"))->toBe("'a\\nb\\tc'");
    }

    #[Test]
    public function truncatesLongStrings(): void
    {
        $rendered = new ValueRenderer()->render(\str_repeat('x', 500));

        Expect::that($rendered)->toEndWith("...' (truncated from 500 characters)");
        Expect::that(\strlen($rendered) < 200)->toBeTrue();
    }

    #[Test]
    public function rendersArraysWithDepthAndItemLimits(): void
    {
        $renderer = new ValueRenderer();

        Expect::that($renderer->render([]))->toBe('[]');
        Expect::that($renderer->render([1, 2]))->toBe('[1, 2]');
        Expect::that($renderer->render(['a' => 1, 'b' => [true]]))->toBe("['a' => 1, 'b' => [true]]");
        Expect::that($renderer->render([[[['deep']]]]))->toBe('[[[[...]]]]');
        Expect::that($renderer->render(\range(1, 15)))->toBe('[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, ... +5 more]');
    }

    #[Test]
    public function rendersEnums(): void
    {
        Expect::that(new ValueRenderer()->render(Signal::Green))->toBe(Signal::class . '::Green');
    }

    #[Test]
    public function rendersDateTimes(): void
    {
        $date = new \DateTimeImmutable('2024-01-02T03:04:05.123456+00:00');

        Expect::that(new ValueRenderer()->render($date))
            ->toBe('DateTimeImmutable(2024-01-02T03:04:05.123456+00:00)');
    }

    #[Test]
    public function rendersPlainObjectsByReflection(): void
    {
        $rendered = new ValueRenderer()->render(new Credentials('ben', 'secret'));

        Expect::that($rendered)->toBe(Credentials::class . " {user: 'ben', password: 'secret'}");
    }

    #[Test]
    public function marksUninitializedProperties(): void
    {
        $rendered = new ValueRenderer()->render(new LateInit());

        Expect::that($rendered)->toBe(LateInit::class . ' {value: (uninitialized)}');
    }

    #[Test]
    public function limitsObjectNestingDepth(): void
    {
        $inner = new Holder(new Holder(new Holder(new Holder(null))));

        Expect::that(new ValueRenderer()->render($inner))->toContain('{...}');
    }

    #[Test]
    public function fallsBackToDebugTypeForUnrenderableValues(): void
    {
        $renderer = new ValueRenderer();
        $stream = \fopen('php://memory', 'r');

        Expect::that($renderer->render(static fn(): int => 1))->toBe('Closure (unrendered)');
        Expect::that($renderer->render($stream))->toBe('resource (stream) (unrendered)');

        if (\is_resource($stream)) {
            \fclose($stream);
        }
    }

    #[Test]
    public function scrubsInvalidUtf8(): void
    {
        $rendered = new ValueRenderer()->render("bad \xB1\x31 bytes");

        Expect::that(\preg_match('//u', $rendered))->toBe(1);
        Expect::that($rendered)->toContain('bad');
    }
}

enum Signal
{
    case Green;
}

final class Credentials
{
    public function __construct(
        public string $user,
        private readonly string $password,
    ) {}

    public function password(): string
    {
        return $this->password;
    }
}

final class LateInit
{
    public string $value;

    public function init(): void
    {
        $this->value = 'set';
    }
}

final class Holder
{
    public function __construct(
        public ?Holder $inner,
    ) {}
}
