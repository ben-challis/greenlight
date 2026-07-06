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

        $expect = new Expect();
        $expect->that($renderer->render(null))->toBe('null');
        $expect->that($renderer->render(true))->toBe('true');
        $expect->that($renderer->render(false))->toBe('false');
        $expect->that($renderer->render(42))->toBe('42');
        $expect->that($renderer->render(-7))->toBe('-7');
        $expect->that($renderer->render(1.5))->toBe('1.5');
        $expect->that($renderer->render(1.0))->toBe('1.0');
        $expect->that($renderer->render(\NAN))->toBe('NAN');
        $expect->that($renderer->render(\INF))->toBe('INF');
        $expect->that($renderer->render('abc'))->toBe("'abc'");
    }

    #[Test]
    public function escapesControlCharactersInStrings(): void
    {
        new Expect()->that(new ValueRenderer()->render("a\nb\tc"))->toBe("'a\\nb\\tc'");
    }

    #[Test]
    public function truncatesLongStrings(): void
    {
        $rendered = new ValueRenderer()->render(\str_repeat('x', 500));

        $expect = new Expect();
        $expect->that($rendered)->toEndWith("...' (truncated from 500 characters)");
        $expect->that(\strlen($rendered) < 200)->toBeTrue();
    }

    #[Test]
    public function rendersArraysWithDepthAndItemLimits(): void
    {
        $renderer = new ValueRenderer();

        $expect = new Expect();
        $expect->that($renderer->render([]))->toBe('[]');
        $expect->that($renderer->render([1, 2]))->toBe('[1, 2]');
        $expect->that($renderer->render(['a' => 1, 'b' => [true]]))->toBe("['a' => 1, 'b' => [true]]");
        $expect->that($renderer->render([[[['deep']]]]))->toBe('[[[[...]]]]');
        $expect->that($renderer->render(\range(1, 15)))->toBe('[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, ... +5 more]');
    }

    #[Test]
    public function rendersEnums(): void
    {
        new Expect()->that(new ValueRenderer()->render(Signal::Green))->toBe(Signal::class . '::Green');
    }

    #[Test]
    public function rendersDateTimes(): void
    {
        $date = new \DateTimeImmutable('2024-01-02T03:04:05.123456+00:00');

        new Expect()
            ->that(new ValueRenderer()->render($date))
            ->toBe('DateTimeImmutable(2024-01-02T03:04:05.123456+00:00)');
    }

    #[Test]
    public function rendersPlainObjectsByReflection(): void
    {
        $rendered = new ValueRenderer()->render(new Credentials('ben', 'secret'));

        new Expect()->that($rendered)->toBe(Credentials::class . " {user: 'ben', password: 'secret'}");
    }

    #[Test]
    public function marksUninitializedProperties(): void
    {
        $rendered = new ValueRenderer()->render(new LateInit());

        new Expect()->that($rendered)->toBe(LateInit::class . ' {value: (uninitialized)}');
    }

    #[Test]
    public function limitsObjectNestingDepth(): void
    {
        $inner = new Holder(new Holder(new Holder(new Holder(null))));

        new Expect()->that(new ValueRenderer()->render($inner))->toContain('{...}');
    }

    #[Test]
    public function fallsBackToDebugTypeForUnrenderableValues(): void
    {
        $renderer = new ValueRenderer();
        $stream = \fopen('php://memory', 'r');

        $expect = new Expect();
        $expect->that($renderer->render(static fn(): int => 1))->toBe('Closure (unrendered)');
        $expect->that($renderer->render($stream))->toBe('resource (stream) (unrendered)');

        if (\is_resource($stream)) {
            \fclose($stream);
        }
    }

    #[Test]
    public function scrubsInvalidUtf8(): void
    {
        $rendered = new ValueRenderer()->render("bad \xB1\x31 bytes");

        $expect = new Expect();
        $expect->that(\preg_match('//u', $rendered))->toBe(1);
        $expect->that($rendered)->toContain('bad');
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
