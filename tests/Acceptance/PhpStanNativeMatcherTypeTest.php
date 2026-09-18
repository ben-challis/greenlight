<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanNativeMatcherTypeTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function nativeMatcherTypesKeepNullableUnionAndIntersectionShapes(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;
            use Greenlight\Tests\Fixture\PhpStanNativeType\SerializableString;

            function greenlightGoodNativeTypeProbe(): void
            {
                Expect::value(null)->toAcceptNullableDateTime();
                Expect::value(new DateTimeImmutable())->toAcceptNullableDateTime();
                Expect::value(1)->toAcceptIntegerOrString();
                Expect::value('one')->toAcceptIntegerOrString();
                Expect::value(new SerializableString())->toAcceptSerializableString();
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightBadNativeTypeProbe(): void
            {
                Expect::value(1)->toAcceptNullableDateTime();
                Expect::value([])->toAcceptIntegerOrString();
                Expect::value(new stdClass())->toAcceptSerializableString();
            }
            PHP,
            FixturePath::get('PhpStanNativeType/probe.neon'),
        );

        Expect::value($probe->exitCode)
            ->because('native matcher types keep nullable union and intersection shapes')
            ->toBe(1);
        Expect::value($probe->goodPassed)->toBeTrue();
        Expect::value($probe->errors)->toBe([
            'Extension matcher toAcceptNullableDateTime() requires subject type DateTimeInterface|null, but the subject has type int.',
            'Extension matcher toAcceptIntegerOrString() requires subject type int|string, but the subject has type array.',
            'Extension matcher toAcceptSerializableString() requires subject type JsonSerializable&Stringable, but the subject has type stdClass.',
        ]);
    }
}
