<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\CliError;
use Greenlight\Cli\ReporterCatalog;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\Output\Output;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\ReporterDefinition;
use Greenlight\Reporting\ReporterProviderError;
use Greenlight\Tests\Unit\Reporting\BufferOutput;
use Greenlight\Tests\Unit\Reporting\RecordingReporter;

final class ReporterCatalogTest
{
    #[Test]
    public function eachSelectionCreatesAFreshReporterWithTheOwnedOutput(): void
    {
        $outputs = [];
        $created = [];
        $catalog = new ReporterCatalog([
            new ReporterDefinition(
                'custom',
                static function (Output $output) use (&$outputs, &$created): Reporter {
                    $outputs[] = $output;
                    $created[] = $reporter = new RecordingReporter();

                    return $reporter;
                },
            ),
        ]);
        $output = new BufferOutput();

        $first = $catalog->create('custom', $output);
        $second = $catalog->create('custom', $output);

        Expect::that($first)->not()->toBe($second);
        Expect::that($created)->toBe([$first, $second]);
        Expect::that($outputs)->toBe([$output, $output]);
    }

    #[Test]
    public function duplicateNamesFailBeforeAFactoryRuns(): void
    {
        $calls = 0;
        $definition = static fn(): ReporterDefinition => new ReporterDefinition(
            'plain',
            static function (Output $output) use (&$calls): Reporter {
                $calls++;

                return new RecordingReporter();
            },
        );

        Expect::that(static fn() => new ReporterCatalog([$definition(), $definition()]))
            ->toThrow(ReporterProviderError::class, '/Reporter name "plain" is registered more than one time\./');
        Expect::that($calls)->toBe(0);
    }

    #[Test]
    public function factoryFailuresIdentifyTheSelectedName(): void
    {
        $catalog = new ReporterCatalog([
            new ReporterDefinition(
                'broken',
                static fn(Output $output): Reporter => throw new \RuntimeException('Cannot start'),
            ),
        ]);

        Expect::that(static fn() => $catalog->create('broken', new BufferOutput()))
            ->toThrow(ReporterProviderError::class, '/Reporter factory "broken" failed: Cannot start\./');
    }

    #[Test]
    public function invalidFactoryResultsIdentifyTheSelectedName(): void
    {
        $definition = new \ReflectionClass(ReporterDefinition::class)->newInstance(
            'invalid',
            static fn(): object => new \stdClass(),
        );

        if (!$definition instanceof ReporterDefinition) {
            throw new \LogicException('Reflection did not create a reporter definition.');
        }

        $catalog = new ReporterCatalog([$definition]);

        Expect::that(static fn() => $catalog->create('invalid', new BufferOutput()))
            ->toThrow(ReporterProviderError::class, '/Reporter factory "invalid" did not return a Reporter object\./');
    }

    #[Test]
    public function unknownNamesListBuiltInAndCustomNamesInRegistrationOrder(): void
    {
        $catalog = new ReporterCatalog([
            new ReporterDefinition('plain', static fn(Output $output): Reporter => new RecordingReporter()),
            new ReporterDefinition('custom', static fn(Output $output): Reporter => new RecordingReporter()),
        ]);

        Expect::that(static fn() => $catalog->create('missing', new BufferOutput()))
            ->toThrow(CliError::class, '/Unknown reporter "missing"\. Select one of: plain, custom\./');
    }
}
