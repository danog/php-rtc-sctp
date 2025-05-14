<?php

namespace Tests\Webrtc\SCTP;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Webrtc\DataChannel\RTCDataChannel;
use Webrtc\DataChannel\RTCDataChannelParameters;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\SCTP\Chunk\AbortChunk;
use Webrtc\SCTP\Chunk\AttributeChunk;
use Webrtc\SCTP\Chunk\BaseInitChunk;
use Webrtc\SCTP\Chunk\BaseParamsChunk;
use Webrtc\SCTP\Chunk\Chunk;
use Webrtc\SCTP\Chunk\CookieEchoChunk;
use Webrtc\SCTP\Chunk\DataChunk;
use Webrtc\SCTP\Chunk\ErrorChunk;
use Webrtc\SCTP\Chunk\ForwardTsnChunk;
use Webrtc\SCTP\Chunk\HeartbeatChunk;
use Webrtc\SCTP\Chunk\InitChunk;
use Webrtc\SCTP\Chunk\ReconfigChunk;
use Webrtc\SCTP\Chunk\SackChunk;
use Webrtc\SCTP\Chunk\ShutdownChunk;
use Webrtc\SCTP\SctpPacket;
use Webrtc\SCTP\Param\StreamAddOutgoingParam;
use Webrtc\SCTP\Param\StreamResetOutgoingParam;
use Webrtc\SCTP\Param\StreamResetResponseParam;
use Webrtc\SCTP\SctpUtility;

#[UsesClass(AttributeChunk::class)]
#[UsesClass(BaseParamsChunk::class)]
#[UsesClass(Chunk::class)]
#[UsesClass(StreamAddOutgoingParam::class)]
#[UsesClass(SctpUtility::class)]
#[UsesClass(BaseInitChunk::class)]
#[UsesClass(DataChunk::class)]
#[UsesClass(ForwardTsnChunk::class)]
#[UsesClass(StreamResetOutgoingParam::class)]
#[UsesClass(ShutdownChunk::class)]
#[UsesClass(StreamResetResponseParam::class)]
#[UsesClass(SackChunk::class)]
#[CoversClass(SctpPacket::class)]
class SctpPacketTest extends TestCase
{
    /**
     * Helper method to roundtrip a packet and verify its contents.
     *
     * @param string $data The binary packet data.
     * @return Chunk The parsed chunk.
     */
    private function roundtripPacket(string $data): Chunk
    {
        [$sourcePort, $destinationPort, $verificationTag, $chunks] = SctpPacket::decode($data);

        $this->assertEquals(5000, $sourcePort);
        $this->assertEquals(5000, $destinationPort);
        $this->assertCount(1, $chunks);

        $output = SctpPacket::encode($sourcePort, $destinationPort, $verificationTag, $chunks[0]);
        $this->assertEquals($data, $output);

        return $chunks[0];
    }

    /**
     * Test parsing an INIT chunk.
     */
    public function testParseInit(): void
    {
        $data = $this->load("sctp_init.bin");
        $chunk = $this->roundtripPacket($data);

        $this->assertInstanceOf(InitChunk::class, $chunk);
        $this->assertEquals(1, $chunk->getType());
        $this->assertEquals(0, $chunk->getFlags());
        $this->assertEquals(82, strlen($chunk->getBody()));
        $this->assertEquals("Webrtc\SCTP\Chunk\InitChunk(flags=0)", (string) $chunk);
    }

    /**
     * Test parsing an INIT chunk with an invalid checksum.
     */
    public function testParseInitInvalidChecksum(): void
    {
        $data = $this->load("sctp_init.bin");
        $data = substr($data, 0, 8) . "\x01\x02\x03\x04" . substr($data, 12);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("SCTP packet has invalid checksum");

        $this->roundtripPacket($data);
    }

    /**
     * Test parsing a truncated packet header.
     */
    public function testParseInitTruncatedPacketHeader(): void
    {
        $data = substr($this->load("sctp_init.bin"), 0, 10);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("SCTP packet length is less than 12 bytes");

        $this->roundtripPacket($data);
    }

    /**
     * Test parsing a COOKIE ECHO chunk.
     */
    public function testParseCookieEcho(): void
    {
        $data = $this->load("sctp_cookie_echo.bin");
        $chunk = $this->roundtripPacket($data);

        $this->assertInstanceOf(CookieEchoChunk::class, $chunk);
        $this->assertEquals(10, $chunk->getType());
        $this->assertEquals(0, $chunk->getFlags());
        $this->assertEquals(8, strlen($chunk->getBody()));
    }

    /**
     * Test parsing an ABORT chunk.
     */
    public function testParseAbort(): void
    {
        $data = $this->load("sctp_abort.bin");
        $chunk = $this->roundtripPacket($data);

        $this->assertInstanceOf(AbortChunk::class, $chunk);
        $this->assertEquals(6, $chunk->getType());
        $this->assertEquals(0, $chunk->getFlags());
        $this->assertEquals(
            [[13, "Expected B-bit for TSN=4ce1f17f, SID=0001, SSN=0000"]],
            $chunk->getParams()
        );
    }

    /**
     * Test parsing a DATA chunk.
     */
    public function testParseData(): void
    {
        $data = $this->load("sctp_data.bin");
        $chunk = $this->roundtripPacket($data);

        $this->assertInstanceOf(DataChunk::class, $chunk);
        $this->assertEquals(0, $chunk->getType());
        $this->assertEquals(3, $chunk->getFlags());
        $this->assertEquals(2584679421, $chunk->getTsn());
        $this->assertEquals(1, $chunk->getStreamId());
        $this->assertEquals(1, $chunk->getStreamSeq());
        $this->assertEquals(51, $chunk->getProtocol());
        $this->assertEquals("ping", $chunk->getUserData());
        $this->assertEquals(
            "DataChunk(flags=3, tsn=2584679421, streamId=1, streamSeq=1)",
            (string) $chunk
        );
    }

    /**
     * Test parsing a DATA chunk with padding.
     */
    public function testParseDataPadding(): void
    {
        $data = $this->load("sctp_data_padding.bin");
        $chunk = $this->roundtripPacket($data);

        $this->assertInstanceOf(DataChunk::class, $chunk);
        $this->assertEquals(0, $chunk->getType());
        $this->assertEquals(3, $chunk->getFlags());
        $this->assertEquals(2584679421, $chunk->getTsn());
        $this->assertEquals(1, $chunk->getStreamId());
        $this->assertEquals(1, $chunk->getStreamSeq());
        $this->assertEquals(51, $chunk->getProtocol());
        $this->assertEquals("M", $chunk->getUserData());
        $this->assertEquals(
            "DataChunk(flags=3, tsn=2584679421, streamId=1, streamSeq=1)",
            (string) $chunk
        );
    }

    /**
     * Test parsing an ERROR chunk.
     */
    public function testParseError(): void
    {
        $data = $this->load("sctp_error.bin");
        $chunk = $this->roundtripPacket($data);

        $this->assertInstanceOf(ErrorChunk::class, $chunk);
        $this->assertEquals(9, $chunk->getType());
        $this->assertEquals(0, $chunk->getFlags());
        $this->assertEquals([[1, "\x30\x39\x00\x00"]], $chunk->getParams());
    }

    /**
     * Test parsing a FORWARD TSN chunk.
     */
    public function testParseForwardTsn(): void
    {
        $data = $this->load("sctp_forward_tsn.bin");
        $chunk = $this->roundtripPacket($data);

        $this->assertInstanceOf(ForwardTsnChunk::class, $chunk);
        $this->assertEquals(192, $chunk->getType());
        $this->assertEquals(0, $chunk->getFlags());
        $this->assertEquals(1234, $chunk->getCumulativeTsn());
        $this->assertEquals([[12, 34]], $chunk->getStreams());
        $this->assertEquals(
            "ForwardTsnChunk(cumulativeTsn=1234, streams=[[12,34]])",
            (string) $chunk
        );
    }

    /**
     * Test parsing a HEARTBEAT chunk.
     */
    public function testParseHeartbeat(): void
    {
        $data = $this->load("sctp_heartbeat.bin");
        $chunk = $this->roundtripPacket($data);

        $this->assertInstanceOf(HeartbeatChunk::class, $chunk);
        $this->assertEquals(4, $chunk->getType());
        $this->assertEquals(0, $chunk->getFlags());
        $this->assertEquals(
            [[1, "\xb5o\xaaZvZ\x06\x00\x00\x00\x00\x00\x00\x00\x00\x00{\x10\x00\x00\x004\xeb\x07F\x10\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"]],
            $chunk->getParams()
        );
    }

    /**
     * Test parsing a RECONFIG RESET OUT chunk.
     */
    public function testParseReconfigResetOut(): void
    {
        $data = $this->load("sctp_reconfig_reset_out.bin");
        $chunk = $this->roundtripPacket($data);

        $this->assertInstanceOf(ReconfigChunk::class, $chunk);
        $this->assertEquals(130, $chunk->getType());
        $this->assertEquals(0, $chunk->getFlags());
        $this->assertEquals(
            [[13, "\x8b\xd8\n[\xe4\x8b\xecs\x8b\xd8\n^\x00\x01"]],
            $chunk->getParams()
        );

        // Outgoing SSN Reset Request Parameter
        $paramData = $chunk->getParams()[0][1];
        $param = StreamResetOutgoingParam::decode($paramData);
        $this->assertEquals(2346191451, $param->getRequestSequence());
        $this->assertEquals(3834375283, $param->getResponseSequence());
        $this->assertEquals(2346191454, $param->getLastTsn());
        $this->assertEquals([1], $param->getStreams());
        $this->assertEquals($paramData, $param->encode());
    }

    /**
     * Test parsing a RECONFIG ADD OUT chunk.
     */
    public function testParseReconfigAddOut(): void
    {
        $data = $this->load("sctp_reconfig_add_out.bin");
        $chunk = $this->roundtripPacket($data);

        $this->assertInstanceOf(ReconfigChunk::class, $chunk);
        $this->assertEquals(130, $chunk->getType());
        $this->assertEquals(0, $chunk->getFlags());
        $this->assertEquals([[17, "\xca\x02\xf60\x00\x10\x00\x00"]], $chunk->getParams());

        // Add Outgoing Streams Request Parameter
        $paramData = $chunk->getParams()[0][1];
        $param = StreamAddOutgoingParam::decode($paramData);
        $this->assertEquals(3389191728, $param->getRequestSequence());
        $this->assertEquals(16, $param->getNewStreams());
        $this->assertEquals($paramData, $param->encode());
    }

    /**
     * Test parsing a RECONFIG RESPONSE chunk.
     */
    public function testParseReconfigResponse(): void
    {
        $data = $this->load("sctp_reconfig_response.bin");
        $chunk = $this->roundtripPacket($data);

        $this->assertInstanceOf(ReconfigChunk::class, $chunk);
        $this->assertEquals(130, $chunk->getType());
        $this->assertEquals(0, $chunk->getFlags());
        $this->assertEquals([[16, "\x91S\x1fT\x00\x00\x00\x01"]], $chunk->getParams());

        // Re-configuration Response Parameter
        $paramData = $chunk->getParams()[0][1];
        $param = StreamResetResponseParam::decode($paramData);
        $this->assertEquals(2438143828, $param->getResponseSequence());
        $this->assertEquals(1, $param->getResult());
        $this->assertEquals($paramData, $param->encode());
    }

    /**
     * Test parsing a SACK chunk.
     */
    public function testParseSack(): void
    {
        $data = $this->load("sctp_sack.bin");
        $chunk = $this->roundtripPacket($data);

        $this->assertInstanceOf(SackChunk::class, $chunk);
        $this->assertEquals(3, $chunk->getType());
        $this->assertEquals(0, $chunk->getFlags());
        $this->assertEquals(2222939037, $chunk->getCumulativeTsn());
        $this->assertEquals([[2, 2], [4, 4]], $chunk->getGaps());
        $this->assertEquals([2222939041], $chunk->getDuplicates());
        $this->assertEquals(
            "SackChunk(flags=0, advertisedRwnd=128160, cumulativeTsn=2222939037, gaps=[[2,2],[4,4]])",
            (string) $chunk
        );
    }

    /**
     * Test parsing a SHUTDOWN chunk.
     */
    public function testParseShutdown(): void
    {
        $data = $this->load("sctp_shutdown.bin");
        $chunk = $this->roundtripPacket($data);

        $this->assertInstanceOf(ShutdownChunk::class, $chunk);
        $this->assertEquals(
            "ShutdownChunk(flags=0, cumulativeTsn=2696426712)",
            (string) $chunk
        );
        $this->assertEquals(7, $chunk->getType());
        $this->assertEquals(0, $chunk->getFlags());
        $this->assertEquals(2696426712, $chunk->getCumulativeTsn());
    }

    /**
     * Helper method to load binary test data from a file.
     *
     * @param string $filename The filename of the test data.
     * @return string The binary data.
     */
    private function load(string $filename): string
    {
        return file_get_contents(__DIR__ . "/fixture/" . $filename);
    }
}