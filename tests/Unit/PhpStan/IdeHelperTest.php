<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\PhpStan\IdeHelper;
use Greenlight\PhpStan\MatcherMap;

final class IdeHelperTest
{
    #[Test]
    public function rendersOneMethodAnnotationPerMatcherWithReflectedSignatures(): void
    {
        $map = MatcherMap::fromConfigFiles([\dirname(__DIR__, 2) . '/Fixture/PhpStanExtension/greenlight.php']);

        $rendered = IdeHelper::render($map);

        Expect::that($rendered)->toContain('namespace Greenlight\Expect;')
            ->and($rendered)->toContain(' * @method self toBeHexadecimal()')
            ->and($rendered)->toContain(' * @method self toHaveDigestLength(int $length)')
            ->and($rendered)->toContain('final class Expectation {}')
            ->and($rendered)->toContain('Never executed or autoloaded');
    }
}
