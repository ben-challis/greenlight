<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\PhpStanProbe;

use function Greenlight\expect;

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

            use Greenlight\Tests\Fixture\PhpStanNativeType\SerializableString;

            use function Greenlight\expect;

            function greenlightGoodNativeTypeProbe(): void
            {
                expect(null)->toAcceptNullableDateTime();
                expect(new DateTimeImmutable())->toAcceptNullableDateTime();
                expect(1)->toAcceptIntegerOrString();
                expect('one')->toAcceptIntegerOrString();
                expect(new SerializableString())->toAcceptSerializableString();
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use function Greenlight\expect;

            function greenlightBadNativeTypeProbe(): void
            {
                expect(1)->toAcceptNullableDateTime();
                expect([])->toAcceptIntegerOrString();
                expect(new stdClass())->toAcceptSerializableString();
            }
            PHP,
            FixturePath::get('PhpStanNativeType/probe.neon'),
        );

        expect($probe->exitCode)
            ->because('native matcher types keep nullable union and intersection shapes')
            ->toBe(1);
        expect($probe->goodPassed)->toBeTrue();
        expect($probe->errors)->toBe([
            'Extension matcher toAcceptNullableDateTime() requires subject type DateTimeInterface|null, but the subject has type int.',
            'Extension matcher toAcceptIntegerOrString() requires subject type int|string, but the subject has type array.',
            'Extension matcher toAcceptSerializableString() requires subject type JsonSerializable&Stringable, but the subject has type stdClass.',
        ]);
    }
}
