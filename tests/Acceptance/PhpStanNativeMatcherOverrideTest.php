<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\IdeHelper;
use Greenlight\PhpStan\MatcherMap;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\PhpStanNativeMatcherOverride\NativeMatcherOverrideExtension;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanNativeMatcherOverrideTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function nativeMatchersKeepTheirSignaturesWhenAnExtensionReusesTheirNames(): void
    {
        $restore = Expect::install([new NativeMatcherOverrideExtension()]);

        try {
            Expect::that(1)->toBeInt();
        } finally {
            $restore();
        }

        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function nativeMatcherOverrideGoodProbe(): void
            {
                Expect::that(1)->toBeInt();
                Expect::eventually(static fn(): int => 1)->within(0.1)->toBeInt();
                Expect::consistently(static fn(): int => 1)->for(0.1)->toBeInt();
                Expect::that(1)->toHavePositiveValue();
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function nativeMatcherOverrideBadProbe(): void
            {
                Expect::that('wrong subject')->toHavePositiveValue();
            }
            PHP,
            FixturePath::get('PhpStanNativeMatcherOverride/probe.neon'),
        );

        Expect::that($probe->exitCode)->toBe(1);
        Expect::that($probe->goodErrors)->toBe([]);
        Expect::that(\count($probe->errors))->toBe(1);
        Expect::that($probe->messages())->toContain('requires subject type int, but the subject has type string');

        $helper = IdeHelper::render(MatcherMap::fromConfigFiles([
            FixturePath::get('PhpStanNativeMatcherOverride/greenlight.php'),
        ]));
        Expect::that($helper)->not()->toContain('@method self toBeInt(');
        Expect::that($helper)->toContain('@method self toHavePositiveValue()');
    }
}
