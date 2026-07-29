<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Protocol\JsonFrameCodec;
use Greenlight\Runner\Protocol\ProtocolError;

final class JsonFrameCodecTest
{
    #[Test]
    public function encodingSubstitutesInvalidUtf8AtTheProtocolBoundary(): void
    {
        $codec = new JsonFrameCodec();
        $frame = $codec->encode(['message' => "query failed: \xB1\x31\xFF row 1"]);
        $body = \substr($frame, 4);

        if ($body === '') {
            Fail::because('Expected the encoded frame to contain a JSON body.');
        }

        $length = \unpack('Nlength', $frame)['length'] ?? null;
        $decoded = $codec->decode($body);
        $message = $decoded['message'] ?? null;

        if (!\is_string($message)) {
            Fail::because('Expected the decoded frame to contain a string message.');
        }

        Expect::that($length)
            ->because('the frame prefix MUST contain the substituted JSON body length')
            ->toBe(\strlen($body))
            ->and($message)
            ->because('the substituted protocol value remains a string')
            ->toBeString();

        Expect::that(\preg_match('//u', $message))
            ->because('the protocol value MUST contain valid UTF-8')
            ->toBe(1)
            ->and($message)
            ->because('UTF-8 substitution preserves readable surrounding text')
            ->toStartWith('query failed: ')
            ->toEndWith(' row 1');
    }

    #[Test]
    public function unsupportedPayloadValuesProduceAProtocolError(): void
    {
        $stream = \fopen('php://memory', 'r');

        if ($stream === false) {
            Fail::because('Expected PHP to open the in-memory stream.');
        }

        try {
            Expect::that(static fn(): string => new JsonFrameCodec()->encode(['stream' => $stream]))
                ->because('unsupported JSON values produce a protocol error')
                ->toThrow(
                    ProtocolError::class,
                    matching: '/Malformed frame: Greenlight cannot encode the payload as JSON:/',
                );
        } finally {
            \fclose($stream);
        }
    }

    #[Test]
    public function malformedJsonBodyProducesAProtocolError(): void
    {
        $codec = new JsonFrameCodec();

        Expect::that(static fn(): array => $codec->decode('{]'))
            ->because('malformed JSON produces a protocol error')
            ->toThrow(
                ProtocolError::class,
                matching: '/Malformed frame: body is not valid JSON:/',
            );
    }

    #[Test]
    public function scalarJsonBodyProducesAProtocolError(): void
    {
        $codec = new JsonFrameCodec();

        Expect::that(static fn(): array => $codec->decode('null'))
            ->because('a JSON scalar is not a protocol envelope')
            ->toThrow(
                ProtocolError::class,
                message: 'Malformed frame: body decodes to null, not a map.',
            );
    }

    /**
     * @param non-empty-string $body
     */
    #[Test]
    #[DataSet('jsonLists')]
    public function jsonListBodyProducesAProtocolError(string $body): void
    {
        $codec = new JsonFrameCodec();

        Expect::that(static fn(): array => $codec->decode($body))
            ->because('a JSON list is not a protocol envelope map')
            ->toThrow(
                ProtocolError::class,
                message: 'Malformed frame: body decodes to list, not a map.',
            );
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function jsonLists(): iterable
    {
        yield 'empty list' => ['[]'];
        yield 'non-empty list' => ['[{"v":1,"t":"ready","p":[]}]'];
    }

    #[Test]
    public function emptyJsonObjectRemainsAValidMap(): void
    {
        Expect::that(new JsonFrameCodec()->decode('{}'))
            ->because('an empty JSON object is still a protocol map')
            ->toBe([]);
    }

    #[Test]
    public function whitespaceWrappedEmptyJsonObjectRemainsAValidMap(): void
    {
        Expect::that(new JsonFrameCodec()->decode("\n \t{}\r\n"))
            ->because('JSON whitespace MUST NOT make an empty object look like a list')
            ->toBe([]);
    }
}
