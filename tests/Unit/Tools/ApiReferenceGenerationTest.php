<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Tools;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final readonly class ApiReferenceGenerationTest
{
    #[Test]
    public function privatePromotedParametersDoNotHidePublicConstructors(): void
    {
        Expect::that($this->reference('api-integrations.md'))
            ->because('private promoted parameters MUST NOT hide a public constructor')
            ->toContain(<<<'MARKDOWN'
                ### `__construct()`

                ```php
                public function __construct(
                    string|\Closure $application,
                    private readonly string $env = 'testing',
                    private readonly bool $refreshBetweenTests = true,
                )
                MARKDOWN);
    }

    #[Test]
    public function mixedVisibilityPromotionIncludesPublicProperties(): void
    {
        Expect::that($this->reference('api-plugins.md'))
            ->because('the API reference MUST include each public promoted property')
            ->toContain('### `$instance`')
            ->toContain('### `$id`')
            ->toContain('### `$metadata`')
            ->not()->toContain('### `$scopes`');
    }

    private function reference(string $file): string
    {
        return (string) \file_get_contents(\dirname(__DIR__, 3) . '/docs/' . $file);
    }
}
