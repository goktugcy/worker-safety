<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Replay;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Exception\ReplayException;
use WorkerSafety\Replay\Http\ChunkedBodyDecoder;

#[CoversClass(ChunkedBodyDecoder::class)]
final class ChunkedBodyDecoderTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'no final chunk' => ["2\r\nok\r\n"];
        yield 'short data' => ["A\r\nok"];
        yield 'invalid size' => ["Z\r\nok\r\n0\r\n\r\n"];
        yield 'missing delimiter' => ["2\r\nok0\r\n\r\n"];
        yield 'trailer cut off' => ["0\r\nX-Test: yes\r\n"];
        yield 'invalid trailer' => ["0\r\nno-colon\r\n\r\n"];
        yield 'extra bytes' => ["0\r\n\r\nextra"];
        yield 'overflowing size' => [str_repeat('f', 32) . "\r\n"];
    }

    #[DataProvider('malformed')]
    public function test_malformed_framing_is_rejected(string $wire): void
    {
        $this->expectException(ReplayException::class);
        (new ChunkedBodyDecoder())->decode('http://localhost', $wire);
    }

    public function test_chunk_boundaries_preserve_binary_body_bytes(): void
    {
        self::assertSame("a\0\r\nb", (new ChunkedBodyDecoder())->decode(
            'http://localhost',
            "2;foo=bar\r\na\0\r\n3\r\n\r\nb\r\n0\r\nX-End: yes\r\n\r\n",
        ));
    }
}
