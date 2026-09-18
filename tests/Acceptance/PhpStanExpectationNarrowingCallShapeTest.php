<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

use function Greenlight\expect;

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
                Expect::value($value)->NOT()->BECAUSE('The value must be an integer.')->toBeString();
                acceptInt($value);
            }

            function uppercaseFactory(int|string $value): void
            {
                Expect::VALUE($value)->toBeString();
                acceptString($value);
            }
            PHP,
            <<<'PHP'
            <?php

            use Greenlight\Expect\Expect;

            function requireString(string $value): void {}

            function negatedString(int|string $value): void
            {
                Expect::value($value)->NOT()->toBeString();
                requireString($value);
            }
            PHP,
        );

        expect($probe->exitCode)->toBe(1);
        expect(\count($probe->errors))->because('PHPStan messages: ' . $probe->messages())->toBe(1);
        expect($probe->goodPassed)->because('PHPStan messages: ' . \implode("\n", $probe->goodErrors))->toBeTrue();
        expect($probe->messages())->toContain('expects string, int given');
    }
}
