<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanExpectationNarrowingCallShapeTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    public function uppercaseFactoryAndModifiersKeepSoundSubjectTypes(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->temporaryDirectory,
            <<<'PHP'
            <?php

            use Greenlight\Expect\Expect;

            function acceptInt(int $value): void {}
            function acceptString(string $value): void {}

            function uppercaseNegation(int|string $value): void
            {
                Expect::that($value)->NOT()->BECAUSE('The value must be an integer.')->toBeString();
                acceptInt($value);
            }

            function uppercaseFactory(int|string $value): void
            {
                Expect::THAT($value)->toBeString();
                acceptString($value);
            }
            PHP,
            <<<'PHP'
            <?php

            use Greenlight\Expect\Expect;

            function requireString(string $value): void {}

            function negatedString(int|string $value): void
            {
                Expect::that($value)->NOT()->toBeString();
                requireString($value);
            }
            PHP,
        );

        Expect::that($probe->exitCode)->toBe(1);
        Expect::that(\count($probe->errors))->because('PHPStan messages: ' . $probe->messages())->toBe(1);
        Expect::that($probe->goodPassed)->because('PHPStan messages: ' . \implode("\n", $probe->goodErrors))->toBeTrue();
        Expect::that($probe->messages())->toContain('expects string, int given');
    }
}
