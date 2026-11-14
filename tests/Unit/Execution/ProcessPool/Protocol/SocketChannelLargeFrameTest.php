<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\JsonFrameCodec;
use Greenlight\Execution\ProcessPool\Protocol\MessageRegistry;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Drain;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Fatal;
use Greenlight\Execution\ProcessPool\Protocol\SocketChannel;
use Greenlight\Expect\Fail;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Execution\ProcessPool\Protocol\ChunkedStream;

use function Greenlight\expect;

final readonly class SocketChannelLargeFrameTest
{
    private const string SCHEME = 'greenlight-chunked';

    public function __construct(
        private StreamWrappers $streamWrappers,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function onePollReadsAFrameThatArrivesInManyParts(): void
    {
        $this->streamWrappers->register(self::SCHEME, ChunkedStream::class);
        $codec = new JsonFrameCodec();
        $message = \str_repeat('worker diagnostic ', 20_000);
        ChunkedStream::$pending = $codec->encode(MessageRegistry::envelope(new Fatal(new ThrowableDetail(
            \RuntimeException::class,
            $message,
            '/fixture/worker.php',
            7,
        ))))
            . $codec->encode(MessageRegistry::envelope(new Drain()));
        $this->cleanup->defer(static function (): void {
            ChunkedStream::$pending = '';
        });
        $stream = \fopen(self::SCHEME . '://channel', 'r+');

        if ($stream === false) {
            Fail::because('The test could not open the chunked stream.');
        }

        $channel = new SocketChannel($stream);
        $this->cleanup->defer($channel->close(...));
        $first = $channel->poll();

        expect($first)
            ->because('one poll MUST read every available part of a large frame')
            ->toBeInstanceOf(Fatal::class);
        expect($first->detail->message)->toBe($message);
        expect($channel->poll())
            ->because('the next poll MUST return the next frame')
            ->toBeInstanceOf(Drain::class);
        expect($channel->poll())->toBeNull();
    }
}
