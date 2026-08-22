<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\JsonFrameCodec;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;

final readonly class JsonFrameCodecEmptyEnvelopeTest
{
    #[Test]
    public function emptyEnvelopeSurvivesTheCodecRoundTrip(): void
    {
        $codec = new JsonFrameCodec();
        $body = \substr($codec->encode([]), 4);

        if ($body === '') {
            Fail::because('Expected the encoded frame to contain a JSON body.');
        }

        Expect::that($body)
            ->because('an empty protocol envelope MUST remain a JSON map')
            ->toBe('{}');
        Expect::that($codec->decode($body))
            ->because('the frame codec MUST decode its empty encoded envelope')
            ->toBe([]);
    }
}
