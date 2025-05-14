<?php

namespace Tests\Webrtc\SCTP;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\SCTP\Chunk\AttributeChunk;
use Webrtc\SCTP\Chunk\Chunk;
use Webrtc\SCTP\Chunk\DataChunk;
use Webrtc\SCTP\InboundStream;
use Webrtc\SCTP\SctpUtility;

#[UsesClass(AttributeChunk::class)]
#[UsesClass(Chunk::class)]
#[UsesClass(DataChunk::class)]
#[UsesClass(SctpUtility::class)]
#[CoversClass(InboundStream::class)]
class InboundStreamTest extends TestCase
{
    private DataChunkFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new DataChunkFactory();
    }

    public function testDuplicate()
    {
        $stream = new InboundStream();
        $chunks = $this->factory->create(["foo", "bar", "baz"]);

        // feed first chunk
        $stream->addChunk($chunks[0]);
        $this->assertEquals([$chunks[0]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([], iterator_to_array($stream->popMessages()));
        $this->assertEquals([$chunks[0]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        // feed first chunk again
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate chunk in reassembly");
        $stream->addChunk($chunks[0]);
    }

    public function testWholeInOrder()
    {
        $stream = new InboundStream();
        $chunks = array_merge(
            $this->factory->create(["foo"]),
            $this->factory->create(["bar"])
        );

        // feed first unfragmented
        $stream->addChunk($chunks[0]);
        $this->assertEquals([$chunks[0]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([[456, 123, "foo"]], iterator_to_array($stream->popMessages()));
        $this->assertEquals([], $stream->getReassembly());
        $this->assertEquals(1, $stream->getSequenceNumber());

        // feed second unfragmented
        $stream->addChunk($chunks[1]);
        $this->assertEquals([$chunks[1]], $stream->getReassembly());
        $this->assertEquals(1, $stream->getSequenceNumber());

        $this->assertEquals([[456, 123, "bar"]], iterator_to_array($stream->popMessages()));
        $this->assertEquals([], $stream->getReassembly());
        $this->assertEquals(2, $stream->getSequenceNumber());
    }

    public function testWholeOutOfOrder()
    {
        $stream = new InboundStream();
        $chunks = array_merge(
            $this->factory->create(["foo"]),
            $this->factory->create(["bar"]),
            $this->factory->create(["baz", "qux"])
        );

        // feed second unfragmented
        $stream->addChunk($chunks[1]);
        $this->assertEquals([$chunks[1]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([], iterator_to_array($stream->popMessages()));
        $this->assertEquals([$chunks[1]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        // feed third partial
        $stream->addChunk($chunks[2]);
        $this->assertEquals([$chunks[1], $chunks[2]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([], iterator_to_array($stream->popMessages()));
        $this->assertEquals([$chunks[1], $chunks[2]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        // feed first unfragmented
        $stream->addChunk($chunks[0]);
        $this->assertEquals([$chunks[0], $chunks[1], $chunks[2]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals(
            [[456, 123, "foo"], [456, 123, "bar"]],
            iterator_to_array($stream->popMessages())
        );
        $this->assertEquals([$chunks[2]], $stream->getReassembly());
        $this->assertEquals(2, $stream->getSequenceNumber());
    }

    public function testFragmentsInOrder()
    {
        $stream = new InboundStream();
        $chunks = $this->factory->create(["foo", "bar", "baz"]);

        // feed first chunk
        $stream->addChunk($chunks[0]);
        $this->assertEquals([$chunks[0]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([], iterator_to_array($stream->popMessages()));
        $this->assertEquals([$chunks[0]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        // feed second chunk
        $stream->addChunk($chunks[1]);
        $this->assertEquals([$chunks[0], $chunks[1]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([], iterator_to_array($stream->popMessages()));
        $this->assertEquals([$chunks[0], $chunks[1]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        // feed third chunk
        $stream->addChunk($chunks[2]);
        $this->assertEquals([$chunks[0], $chunks[1], $chunks[2]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([[456, 123, "foobarbaz"]], iterator_to_array($stream->popMessages()));
        $this->assertEquals([], $stream->getReassembly());
        $this->assertEquals(1, $stream->getSequenceNumber());
    }

    public function testFragmentsOutOfOrder()
    {
        $stream = new InboundStream();
        $chunks = $this->factory->create(["foo", "bar", "baz"]);

        // feed third chunk
        $stream->addChunk($chunks[2]);
        $this->assertEquals([$chunks[2]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([], iterator_to_array($stream->popMessages()));
        $this->assertEquals([$chunks[2]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        // feed first chunk
        $stream->addChunk($chunks[0]);
        $this->assertEquals([$chunks[0], $chunks[2]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([], iterator_to_array($stream->popMessages()));
        $this->assertEquals([$chunks[0], $chunks[2]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        // feed second chunk
        $stream->addChunk($chunks[1]);
        $this->assertEquals([$chunks[0], $chunks[1], $chunks[2]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([[456, 123, "foobarbaz"]], iterator_to_array($stream->popMessages()));
        $this->assertEquals([], $stream->getReassembly());
        $this->assertEquals(1, $stream->getSequenceNumber());
    }

    public function testUnorderedNoFragments()
    {
        $stream = new InboundStream();
        $chunks = array_merge(
            $this->factory->create(["foo"], false),
            $this->factory->create(["bar"], false),
            $this->factory->create(["baz"], false)
        );

        // feed second unfragmented
        $stream->addChunk($chunks[1]);
        $this->assertEquals([$chunks[1]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([[456, 123, "bar"]], iterator_to_array($stream->popMessages()));
        $this->assertEquals([], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        // feed third unfragmented
        $stream->addChunk($chunks[2]);
        $this->assertEquals([$chunks[2]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([[456, 123, "baz"]], iterator_to_array($stream->popMessages()));
        $this->assertEquals([], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        // feed first unfragmented
        $stream->addChunk($chunks[0]);
        $this->assertEquals([$chunks[0]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([[456, 123, "foo"]], iterator_to_array($stream->popMessages()));
        $this->assertEquals([], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());
    }

    public function testUnorderedWithFragments()
    {
        $stream = new InboundStream();
        $chunks = array_merge(
            $this->factory->create(["foo", "bar"], false),
            $this->factory->create(["baz"], false),
            $this->factory->create(["qux", "quux", "corge"], false)
        );

        // feed second fragment of first message
        $stream->addChunk($chunks[1]);
        $this->assertEquals([$chunks[1]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());


        $this->assertEquals([], iterator_to_array($stream->popMessages()));
        $this->assertEquals([$chunks[1]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        // feed second message
        $stream->addChunk($chunks[2]);
        $this->assertEquals([$chunks[1], $chunks[2]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([[456, 123, "baz"]], iterator_to_array($stream->popMessages()));
        $this->assertEquals([$chunks[1]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        // feed first fragment of third message
        $stream->addChunk($chunks[3]);
        $this->assertEquals([$chunks[1], $chunks[3]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([], iterator_to_array($stream->popMessages()));
        $this->assertEquals([$chunks[1], $chunks[3]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        // feed third fragment of third message
        $stream->addChunk($chunks[5]);
        $this->assertEquals([$chunks[1], $chunks[3], $chunks[5]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([], iterator_to_array($stream->popMessages()));
        $this->assertEquals([$chunks[1], $chunks[3], $chunks[5]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        // feed second fragment of third message
        $stream->addChunk($chunks[4]);
        $this->assertEquals(
            [$chunks[1], $chunks[3], $chunks[4], $chunks[5]],
            $stream->getReassembly()
        );
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([[456, 123, "quxquuxcorge"]], iterator_to_array($stream->popMessages()));
        $this->assertEquals([$chunks[1]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        // feed first fragment of first message
        $stream->addChunk($chunks[0]);
        $this->assertEquals([$chunks[0], $chunks[1]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $this->assertEquals([[456, 123, "foobar"]], iterator_to_array($stream->popMessages()));
        $this->assertEquals([], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());
    }

    public function testPruneChunks()
    {
        $stream = new InboundStream();
        $factory = new DataChunkFactory(100);
        $chunks = array_merge(
            $factory->create(["foo", "bar"]),
            $factory->create(["baz", "qux"])
        );

        foreach ([1, 2] as $i) {
            $stream->addChunk($chunks[$i]);
            $this->assertEquals([], iterator_to_array($stream->popMessages()));
        }
        $this->assertEquals([$chunks[1], $chunks[2]], $stream->getReassembly());
        $this->assertEquals(0, $stream->getSequenceNumber());

        $stream->setSequenceNumber(2);
        $this->assertEquals([], iterator_to_array($stream->popMessages()));
        $this->assertEquals([$chunks[1], $chunks[2]], $stream->getReassembly());
        $this->assertEquals(2, $stream->getSequenceNumber());

        $this->assertEquals(3, $stream->pruneChunks(101));
        $this->assertEquals([$chunks[2]], $stream->getReassembly());
        $this->assertEquals(2, $stream->getSequenceNumber());
    }
}