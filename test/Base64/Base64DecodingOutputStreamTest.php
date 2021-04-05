<?php

namespace Amp\ByteStream\Test\Base64;

use Amp\ByteStream\Base64\Base64DecodingOutputStream;
use Amp\ByteStream\OutputBuffer;
use Amp\ByteStream\StreamException;
use Amp\PHPUnit\AsyncTestCase;

class Base64DecodingOutputStreamTest extends AsyncTestCase
{
    public function testWrite(): void
    {
        $buffer = new OutputBuffer;
        $stream = new Base64DecodingOutputStream($buffer);

        $stream->write('Zm9');
        $stream->write('');
        $stream->write('vLmJhcg==');
        $stream->end();

        self::assertSame('foo.bar', $buffer->buffer()->join());
    }

    public function testEnd(): void
    {
        $buffer = new OutputBuffer;
        $stream = new Base64DecodingOutputStream($buffer);

        $stream->write('Zm9');
        $stream->write('');
        $stream->end('vLmJhcg==');

        self::assertSame('foo.bar', $buffer->buffer()->join());
    }

    public function testInvalidDataMissingPadding(): void
    {
        $buffer = new OutputBuffer;
        $stream = new Base64DecodingOutputStream($buffer);

        $stream->write('Zm9');
        $stream->write('');

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Invalid base64 near offset 3');

        $stream->end('vLmJhcg=');
    }

    public function testInvalidDataChar(): void
    {
        $buffer = new OutputBuffer;
        $stream = new Base64DecodingOutputStream($buffer);

        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Invalid base64 near offset 0');

        $stream->write('Z!fsdf');
    }
}
