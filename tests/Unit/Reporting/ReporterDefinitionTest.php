<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\Output;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\ReporterDefinition;

final readonly class ReporterDefinitionTest
{
    #[Test]
    public function acceptsACommandLineNameAndFactory(): void
    {
        $factory = static fn(Output $output): Reporter => new RecordingReporter();
        $definition = new ReporterDefinition('company-json', $factory);

        Expect::that($definition->name)->toBe('company-json');
        Expect::that($definition->factory)->toBe($factory);
    }

    /** @param non-empty-string $name */
    #[Test]
    #[DataSet('invalidNames')]
    public function rejectsNamesThatAreNotCommandLineTokens(string $name): void
    {
        Expect::that(static fn() => new ReporterDefinition(
            $name,
            static fn(Output $output): Reporter => new RecordingReporter(),
        ))->toThrow(\InvalidArgumentException::class);
    }

    #[Test]
    public function rejectsAnEmptyName(): void
    {
        $factory = static fn(Output $output): Reporter => new RecordingReporter();

        Expect::that(static fn(): object => new \ReflectionClass(ReporterDefinition::class)->newInstance('', $factory))
            ->toThrow(\InvalidArgumentException::class);
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function invalidNames(): iterable
    {
        yield 'uppercase' => ['Company'];
        yield 'starts with digit' => ['1company'];
        yield 'underscore' => ['company_json'];
        yield 'space' => ['company json'];
    }
}
