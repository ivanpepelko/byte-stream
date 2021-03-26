<?php

namespace Amp\ByteStream\Test;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\OutputBuffer;
use Amp\PHPUnit\AsyncTestCase;
use function Amp\await;

class OutputBufferTest extends AsyncTestCase
{
    public function testWrite(): void
    {
        $output = new OutputBuffer();
        $output->write('foo');
        $output->end();

        self::assertSame('foo', await($output));
    }

    public function testEnd(): void
    {
        $output = new OutputBuffer();
        $output->write('foo');
        $output->end('bar');

        self::assertSame('foobar', await($output));
    }

    public function testThrowsOnWritingToClosedBuffer(): void
    {
        $this->expectException(ClosedException::class);

        $output = new OutputBuffer();
        $output->end('foo');
        $output->write('bar');
    }

    public function testThrowsOnEndingToClosedBuffer(): void
    {
        $this->expectException(ClosedException::class);

        $output = new OutputBuffer();
        $output->end('foo');
        $output->end('bar');
    }
}
