<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ValueRenderer;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Expect\Credentials;
use Greenlight\Tests\Fixture\Expect\Holder;
use Greenlight\Tests\Fixture\Expect\HookedProperties;
use Greenlight\Tests\Fixture\Expect\LateInit;
use Greenlight\Tests\Fixture\Expect\Signal;
use Greenlight\Tests\Support\MemoryStream;

final readonly class ValueRendererTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function rendersScalarsAndNull(): void
    {
        $renderer = new ValueRenderer();

        Expect::that($renderer->render(null))->because('renders scalars and null')->toBe('null');
        Expect::that($renderer->render(true))->because('renders scalars and null')->toBe('true');
        Expect::that($renderer->render(false))->because('renders scalars and null')->toBe('false');
        Expect::that($renderer->render(42))->because('renders scalars and null')->toBe('42');
        Expect::that($renderer->render(-7))->because('renders scalars and null')->toBe('-7');
        Expect::that($renderer->render(1.5))->because('renders scalars and null')->toBe('1.5');
        Expect::that($renderer->render(1.0))->because('renders scalars and null')->toBe('1.0');
        Expect::that($renderer->render(\NAN))->because('renders scalars and null')->toBe('NAN');
        Expect::that($renderer->render(\INF))->because('renders scalars and null')->toBe('INF');
        Expect::that($renderer->render('abc'))->because('renders scalars and null')->toBe("'abc'");
    }

    #[Test]
    public function escapesControlCharactersInStrings(): void
    {
        $rendered = new ValueRenderer()->render("backslash\\ newline\ncarriage\r tab\t nul\0");

        Expect::that($rendered)
            ->because('diagnostic strings escape every control character onto one line')
            ->toBe("'backslash\\\\ newline\\ncarriage\\r tab\\t nul\\0'");
    }

    #[Test]
    public function escapesRemainingUnicodeControlCharactersInStrings(): void
    {
        $rendered = new ValueRenderer()->render("\u{0001}\u{001B}\u{007F}\u{0085}");

        Expect::that($rendered)
            ->because('diagnostic strings MUST NOT preserve terminal or line control characters')
            ->toBe("'\\u{0001}\\u{001B}\\u{007F}\\u{0085}'");
    }

    #[Test]
    public function escapesTheStringDelimiter(): void
    {
        Expect::that(new ValueRenderer()->render("can't"))
            ->because('rendered string delimiters MUST remain unambiguous')
            ->toBe("'can\\'t'");
    }

    #[Test]
    public function truncatesLongStrings(): void
    {
        $rendered = new ValueRenderer()->render(\str_repeat('x', 500));

        Expect::that($rendered)->because('truncates long strings')->toEndWith("...' (truncated from 500 characters)");
        Expect::that(\strlen($rendered))->because('truncates long strings')->toBeLessThan(200);
    }

    #[Test]
    public function truncatesUnicodeStringsByCodePoint(): void
    {
        $rendered = new ValueRenderer()->render(\str_repeat('é', 121));

        Expect::that($rendered)
            ->because('diagnostic truncation MUST preserve complete Unicode characters')
            ->toBe(
                "'" . \str_repeat('é', 120) . "...' (truncated from 121 characters)",
            );
    }

    #[Test]
    public function truncationDoesNotSplitAnEscapedCharacter(): void
    {
        $rendered = new ValueRenderer()->render(\str_repeat('a', 119) . "\ntail");

        Expect::that($rendered)
            ->because('diagnostic truncation MUST keep escape sequences complete')
            ->toBe(
                "'" . \str_repeat('a', 119) . "...' (truncated from 124 characters)",
            );
    }

    #[Test]
    public function rendersArraysWithDepthAndItemLimits(): void
    {
        $renderer = new ValueRenderer();

        Expect::that($renderer->render([]))->because('renders arrays with depth and item limits')->toBe('[]');
        Expect::that($renderer->render([1, 2]))->because('renders arrays with depth and item limits')->toBe('[1, 2]');
        Expect::that($renderer->render(['a' => 1, 'b' => [true]]))->because('renders arrays with depth and item limits')->toBe("['a' => 1, 'b' => [true]]");
        Expect::that($renderer->render([[[['deep']]]]))->because('renders arrays with depth and item limits')->toBe('[[[[...]]]]');
        Expect::that($renderer->render(\range(1, 15)))->because('renders arrays with depth and item limits')->toBe('[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, ... +5 more]');
    }

    #[Test]
    public function rendersEnums(): void
    {
        Expect::that(new ValueRenderer()->render(Signal::Green))->because('renders enums')->toBe(Signal::class . '::Green');
    }

    #[Test]
    public function rendersDateTimes(): void
    {
        $date = new \DateTimeImmutable('2024-01-02T03:04:05.123456+00:00');

        Expect::that(new ValueRenderer()->render($date))->because('renders date times')
            ->toBe('DateTimeImmutable(2024-01-02T03:04:05.123456+00:00)');
    }

    #[Test]
    public function rendersPlainObjectsByReflection(): void
    {
        $rendered = new ValueRenderer()->render(new Credentials('ben', 'secret'));

        Expect::that($rendered)->because('renders plain objects by reflection')->toBe(Credentials::class . " {user: 'ben', password: 'secret'}");
    }

    #[Test]
    public function marksUninitializedProperties(): void
    {
        $rendered = new ValueRenderer()->render(new LateInit());

        Expect::that($rendered)->because('marks uninitialized properties')->toBe(LateInit::class . ' {value: (uninitialized)}');
    }

    #[Test]
    public function rendersHookedPropertiesWithoutInvokingUserCode(): void
    {
        Expect::that(new ValueRenderer()->render(new HookedProperties()))
            ->because('failure rendering MUST NOT invoke property hooks')
            ->toBe(HookedProperties::class . " {backed: 'stored', virtual: (virtual)}");
    }

    #[Test]
    public function limitsObjectNestingDepth(): void
    {
        $inner = new Holder(new Holder(new Holder(new Holder(null))));

        Expect::that(new ValueRenderer()->render($inner))->because('limits object nesting depth')->toBe(
            Holder::class . ' {inner: '
            . Holder::class . ' {inner: '
            . Holder::class . ' {inner: '
            . Holder::class . ' {...}}}}',
        );
    }

    #[Test]
    public function fallsBackToDebugTypeForUnrenderableValues(): void
    {
        $renderer = new ValueRenderer();
        $stream = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stream));

        Expect::that($renderer->render(static fn(): int => 1))->because('the renderer uses the debug type for unrenderable values')->toBe('Closure (unrendered)');
        Expect::that($renderer->render($stream))->because('the renderer uses the debug type for unrenderable values')->toBe('resource (stream) (unrendered)');
    }

    #[Test]
    public function scrubsInvalidUtf8(): void
    {
        $rendered = new ValueRenderer()->render("bad \xB1\x31 bytes");

        Expect::that($rendered)->because('scrubs invalid UTF-8')->toMatch('//u');
        Expect::that($rendered)->because('scrubs invalid UTF-8')->toContain('bad');
    }
}
