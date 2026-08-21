<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorVersionedExtensionRequirementTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function versionedExtensionRequirementsRemainUnchanged(): void
    {
        $source = <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Attributes\RequiresPhpExtension;
            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                #[RequiresPhpExtension('json', '>=99')]
                public function testVersionedExtension(): void
                {
                    $this->assertTrue(true);
                }
            }

            PHP_WRAP;

        $probe = RectorProbe::convert(
            $this->tempDirectory,
            $source,
            name: 'versioned-extension-requirement',
        );

        Expect::that($probe->changed)
            ->because('Greenlight cannot preserve an extension version constraint')
            ->toBeFalse();
        Expect::that($probe->code)
            ->toBe($source)
            ->toContain("#[RequiresPhpExtension('json', '>=99')]");
    }
}
