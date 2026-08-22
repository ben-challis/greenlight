<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\IntegrationFixture;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\IntegrationFixture\SensitiveValue;

final readonly class SensitiveValueTest
{
    #[Test]
    public function valueIsRevealedOnlyOnExplicitRequest(): void
    {
        $secret = 'database-password';
        $value = new SensitiveValue($secret);

        Expect::that(\hash_equals($secret, $value->reveal()))
            ->because('a sensitive fixture value MUST reveal its original value explicitly')
            ->toBe(true);
    }

    #[Test]
    public function debugAndExportRepresentationsDoNotDiscloseTheValue(): void
    {
        $secret = 'database-password';
        $value = new SensitiveValue($secret);

        \ob_start();
        \var_dump($value);
        $dump = \ob_get_clean();
        $export = \var_export($value, true);

        Expect::that($value->__debugInfo() === ['value' => '[redacted]'])
            ->because('debug information MUST contain only the redaction marker')
            ->toBe(true);
        Expect::that(\is_string($dump) && \str_contains($dump, '[redacted]'))
            ->because('object dumps MUST identify the redacted value')
            ->toBe(true);
        Expect::that(\is_string($dump) && \str_contains($dump, $secret))
            ->because('object dumps MUST NOT disclose the sensitive value')
            ->toBe(false);
        Expect::that(\str_contains($export, $secret))
            ->because('object exports MUST NOT disclose the sensitive value')
            ->toBe(false);
    }
}
