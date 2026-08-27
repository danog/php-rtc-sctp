<?php

namespace Tests\Webrtc\SCTP;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use SplQueue;
use Symfony\Bridge\PhpUnit\ClockMock;
use Webrtc\DataChannel\Enum\State as DataChannelState;
use Webrtc\DataChannel\RTCDataChannel;
use Webrtc\DataChannel\RTCDataChannelParameters;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\ICE\Enum\IceRole;
use Webrtc\ICE\RTCIceTransport;
use Webrtc\SCTP\Chunk\AttributeChunk;
use Webrtc\SCTP\Chunk\BaseInitChunk;
use Webrtc\SCTP\Chunk\BaseParamsChunk;
use Webrtc\SCTP\Chunk\Chunk;
use Webrtc\SCTP\Chunk\ChunkInterface;
use Webrtc\SCTP\Chunk\DataChunk;
use Webrtc\SCTP\Chunk\ForwardTsnChunk;
use Webrtc\SCTP\Chunk\HeartbeatAckChunk;
use Webrtc\SCTP\Chunk\HeartbeatChunk;
use Webrtc\SCTP\Chunk\SackChunk;
use Webrtc\SCTP\Chunk\ShutdownAckChunk;
use Webrtc\SCTP\Chunk\ShutdownChunk;
use Webrtc\SCTP\Chunk\ShutdownCompleteChunk;
use Webrtc\SCTP\Enum\State;
use Webrtc\SCTP\Exception\SctpException;
use Webrtc\SCTP\InboundStream;
use Webrtc\SCTP\Param\StreamAddOutgoingParam;
use Webrtc\SCTP\Param\StreamResetResponseParam;
use Webrtc\SCTP\RTCSctpTransport;
use Webrtc\SCTP\SctpConstant;
use Webrtc\SCTP\SctpPacket;
use Webrtc\SCTP\SctpTimer;
use Webrtc\SCTP\SctpUtility;
use Webrtc\Stats\enum\TLSState;
use function Amp\async;
use function Amp\delay;

#[UsesClass(AttributeChunk::class)]
#[UsesClass(BaseInitChunk::class)]
#[UsesClass(BaseParamsChunk::class)]
#[UsesClass(Chunk::class)]
#[UsesClass(SackChunk::class)]
#[UsesClass(DataChunk::class)]
#[UsesClass(InboundStream::class)]
#[UsesClass(ForwardTsnChunk::class)]
#[UsesClass(ShutdownChunk::class)]
#[UsesClass(StreamAddOutgoingParam::class)]
#[UsesClass(StreamResetResponseParam::class)]
#[UsesClass(SctpPacket::class)]
#[UsesClass(SctpTimer::class)]
#[UsesClass(SctpUtility::class)]
#[UsesClass(\Webrtc\AVCodec\Context\Context::class)]
#[UsesClass(\Webrtc\AVCodec\Frame\AudioFrame::class)]
#[UsesClass(\Webrtc\AVCodec\Frame\Frame::class)]
#[UsesClass(\Webrtc\DataChannel\RTCDataChannel::class)]
#[UsesClass(\Webrtc\DataChannel\RTCDataChannelParameters::class)]
#[UsesClass(\Webrtc\RTCP\RtcpByePacket::class)]
#[UsesClass(\Webrtc\RTP\Receiver\DecoderQueue::class)]
#[UsesClass(\Webrtc\RTP\Sender\RTCRtpSender::class)]
#[CoversClass(RTCSctpTransport::class)]
class RTCSctpTransportTest extends TestCase
{
    private SplQueue $receivedServerQueue;
    private SplQueue $receivedClientQueue;
    private int $lossProbability = 0;

    public function setUp(): void
    {
        $this->receivedServerQueue = new SplQueue();
        $this->receivedClientQueue = new SplQueue();
        ClockMock::register(RTCSctpTransport::class);
    }

    protected function tearDown(): void
    {
        // Disable ClockMock after the test
        ClockMock::withClockMock(false);
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testConstruct()
    {
        $dtlsTransportMock = $this->createDtlsTransportMock();
        $sctpTransport = new RTCSctpTransport($dtlsTransportMock);

        $this->assertSame($dtlsTransportMock, $sctpTransport->getDtlsTransport());
        $this->assertEquals(5000, $sctpTransport->getPort());
    }

    /**
     * @throws RandomException
     */
    public function testConstructInvalidDtlsTransportState()
    {
        $closedTransportMock = $this->getMockBuilder(RTCDtlsTransportMock::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getState'])
            ->getMock();
        $closedTransportMock->expects($this->once())
            ->method('getState')
            ->willReturn(TLSState::CLOSED);
        $this->expectException(SctpException::class);
        new RTCSctpTransport($closedTransportMock);
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testConnectBrokenTransport()
    {
        $this->lossProbability = 100; // Transport with 100% loss never connects.
        [$client, $server] = $this->createSctpTransport();

        $client->setRto(.01);
        $server->setRto(.01);

        $server->start($client->getPort());
        $client->start($server->getPort());

        // Wait async until to connect or fail
        $this->asyncSleep(.1);

        $this->assertEquals(State::CLOSED, $client->getState());
        $this->assertEquals(State::CONNECTING, $server->getState());
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testConnectLossyTransport()
    {
        $this->lossProbability = 30; // Transport with 30% loss eventually connects.
        [$client, $server] = $this->createSctpTransport();

        $client->setRto(.01);
        $server->setRto(.01);

        $server->start($client->getPort());
        $client->start($server->getPort());

        // Wait async until to connect or fail
        $this->asyncSleep(.05);

        $this->assertEquals(State::ESTABLISHED, $client->getState());
        $this->assertEquals(State::ESTABLISHED, $server->getState());

        for ($i = 0; $i < 20; $i++) {
            $message = [123, $i, "ping"];
            $client->sendDataStream(...$message);
        }

        // Chunks are sent from their own fibers so timer callbacks are never held up by the
        // transport, so give the loop a turn before counting what arrived.
        $this->asyncSleep(.05);

        //  Should more than 70% success
        $this->assertGreaterThan(10, count($this->getLatestDataChunk()));

        $server->stop();
        $client->stop();
    }

    public function testConnectClientLimitsStreams()
    {
        [$client, $server] = $this->createSctpTransport();

        // Set client stream limits
        $client->setInboundStreamsMax(2048);
        $client->setOutboundStreamsCount(256);

        // Initial assertions
        $this->assertNull($client->getMaxChannels());
        $this->assertNull($server->getMaxChannels());

        $server->start($client->getPort());
        $client->start($server->getPort());

        // Wait async until to connect or fail
        $this->asyncSleep(.01);

        // Check outcome
        $this->assertEquals(256, $client->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $client->getState());
        $this->assertEquals(2048, $client->getInboundStreamsCount());
        $this->assertEquals(256, $client->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $client->getRemoteExtensions());

        $this->assertEquals(256, $server->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $server->getState());
        $this->assertEquals(256, $server->getInboundStreamsCount());
        $this->assertEquals(2048, $server->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $server->getRemoteExtensions());

        $param = new StreamAddOutgoingParam(
            $client->getReconfigRequestSeq(),
            16
        );
        $client->sendReconfigParam($param);

        // The reconfiguration is sent from its own fiber, so let the loop deliver it.
        $this->asyncSleep(.05);

        // Verify server's updated stream limits
        $this->assertEquals(272, $server->getMaxChannels());
        $this->assertEquals(272, $server->getInboundStreamsCount());
        $this->assertEquals(2048, $server->getOutboundStreamsCount());
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testConnectServerLimitsStreams()
    {
         // All packets will be delivered
        [$client, $server] = $this->createSctpTransport();

        $client->setInboundStreamsMax(2048);
        $client->setOutboundStreamsCount(256);
        $this->assertNull($client->getMaxChannels());
        $this->assertNull($server->getMaxChannels());

        $server->start($client->getPort());
        $client->start($server->getPort());

        // Wait async until to connect or fail
        $this->asyncSleep(.01);

        $this->assertEquals(256, $client->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $client->getState());
        $this->assertEquals(2048, $client->getInboundStreamsCount());
        $this->assertEquals(256, $client->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $client->getRemoteExtensions());
        $this->assertEquals(256, $server->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $server->getState());
        $this->assertEquals(256, $server->getInboundStreamsCount());
        $this->assertEquals(2048, $server->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $server->getRemoteExtensions());
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testConnectThenClientCreatesDataChannel()
    {
        [$client, $server] = $this->createSctpTransport();

        $this->assertFalse($client->isServer());
        $this->assertNull($client->getMaxChannels());
        $this->assertTrue($server->isServer());
        $this->assertNull($server->getMaxChannels());

        $server->start($client->getPort());
        $client->start($server->getPort());

        // Wait async until to connect or fail
        $this->asyncSleep(.01);

        $clientChannels = [];
        $serverChannels = [];
        $this->getTrackChannels($client, $clientChannels);
        $this->getTrackChannels($server, $serverChannels);

        $this->assertEquals(65535, $client->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $client->getState());
        $this->assertEquals(65535, $client->getInboundStreamsCount());
        $this->assertEquals(65535, $client->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $client->getRemoteExtensions());
        $this->assertEquals(65535, $server->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $server->getState());
        $this->assertEquals(65535, $server->getInboundStreamsCount());
        $this->assertEquals(65535, $server->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $server->getRemoteExtensions());

        // Create data channel
        $channel = new RTCDataChannel($client, new RTCDataChannelParameters("chat"));
        $this->assertNull($channel->getId());
        $this->assertEquals("chat", $channel->getLabel());

        $this->asyncSleep(.01);

        $this->assertEquals(1, $channel->getId());
        $this->assertEquals("chat", $channel->getLabel());
        $this->assertCount(0, $clientChannels);
        $this->assertCount(1, $serverChannels);
        $this->assertEquals(1, $serverChannels[0]->getId());
        $this->assertEquals("chat", $serverChannels[0]->getLabel());

        $server->stop();
        $client->stop();
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testConnectThenClientCreatesDataChannelWithCustomId()
    {
         // All packets will be delivered
        [$client, $server] = $this->createSctpTransport();

        $this->assertFalse($client->isServer());
        $this->assertNull($client->getMaxChannels());
        $this->assertTrue($server->isServer());
        $this->assertNull($server->getMaxChannels());

        $server->start($client->getPort());
        $client->start($server->getPort());

        // Wait async until to connect or fail
        $this->asyncSleep(.01);

        $clientChannels = [];
        $serverChannels = [];
        $this->getTrackChannels($client, $clientChannels);
        $this->getTrackChannels($server, $serverChannels);

        $this->assertEquals(65535, $client->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $client->getState());
        $this->assertEquals(65535, $client->getInboundStreamsCount());
        $this->assertEquals(65535, $client->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $client->getRemoteExtensions());
        $this->assertEquals(65535, $server->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $server->getState());
        $this->assertEquals(65535, $server->getInboundStreamsCount());
        $this->assertEquals(65535, $server->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $server->getRemoteExtensions());

        // Create data channels
        $channel = new RTCDataChannel($client, new RTCDataChannelParameters("chat", id: 100));
        $this->assertEquals(100, $channel->getId());
        $this->assertEquals("chat", $channel->getLabel());

        $channel2 = new RTCDataChannel($client, new RTCDataChannelParameters("chat", id: 101));
        $this->assertEquals(101, $channel2->getId());
        $this->assertEquals("chat", $channel2->getLabel());

        $this->asyncSleep(.01);

        $this->assertCount(0, $clientChannels);
        $this->assertCount(2, $serverChannels);
        $this->assertEquals(100, $serverChannels[0]->getId());
        $this->assertEquals("chat", $serverChannels[0]->getLabel());
        $this->assertEquals(101, $serverChannels[1]->getId());
        $this->assertEquals("chat", $serverChannels[1]->getLabel());

        $server->stop();
        $client->stop();
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testConnectThenClientCreatesDataChannelWithCustomIdAndThenNormal()
    {
         // All packets will be delivered
        [$client, $server] = $this->createSctpTransport();

        $this->assertFalse($client->isServer());
        $this->assertNull($client->getMaxChannels());
        $this->assertTrue($server->isServer());
        $this->assertNull($server->getMaxChannels());

        $server->start($client->getPort());
        $client->start($server->getPort());

        // Wait async until to connect or fail
        $this->asyncSleep(.01);

        $clientChannels = [];
        $serverChannels = [];
        $this->getTrackChannels($client, $clientChannels);
        $this->getTrackChannels($server, $serverChannels);

        $this->assertEquals(65535, $client->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $client->getState());
        $this->assertEquals(65535, $client->getInboundStreamsCount());
        $this->assertEquals(65535, $client->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $client->getRemoteExtensions());
        $this->assertEquals(65535, $server->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $server->getState());
        $this->assertEquals(65535, $server->getInboundStreamsCount());
        $this->assertEquals(65535, $server->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $server->getRemoteExtensions());

        // Create data channels
        $channel = new RTCDataChannel($client, new RTCDataChannelParameters("chat"));
        $this->assertNull($channel->getId());
        $this->assertEquals("chat", $channel->getLabel());

        $channel2 = new RTCDataChannel($client, new RTCDataChannelParameters("chat"));
        $this->assertNull($channel2->getId());
        $this->assertEquals("chat", $channel2->getLabel());

        $this->asyncSleep(.01);

        $this->assertCount(0, $clientChannels);
        $this->assertCount(2, $serverChannels);
        $this->assertEquals(1, $serverChannels[0]->getId());
        $this->assertEquals("chat", $serverChannels[0]->getLabel());
        $this->assertEquals(3, $serverChannels[1]->getId());
        $this->assertEquals("chat", $serverChannels[1]->getLabel());

        $server->stop();
        $client->stop();
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testConnectThenClientCreatesSecondDataChannelWithCustomAlreadyUsedId()
    {
         // All packets will be delivered
        [$client, $server] = $this->createSctpTransport();

        $this->assertFalse($client->isServer());
        $this->assertNull($client->getMaxChannels());
        $this->assertTrue($server->isServer());
        $this->assertNull($server->getMaxChannels());

        $server->start($client->getPort());
        $client->start($server->getPort());

        // Wait async until to connect or fail
        $this->asyncSleep(.01);

        $clientChannels = [];
        $serverChannels = [];
        $this->getTrackChannels($client, $clientChannels);
        $this->getTrackChannels($server, $serverChannels);

        $this->assertEquals(65535, $client->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $client->getState());
        $this->assertEquals(65535, $client->getInboundStreamsCount());
        $this->assertEquals(65535, $client->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $client->getRemoteExtensions());
        $this->assertEquals(65535, $server->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $server->getState());
        $this->assertEquals(65535, $server->getInboundStreamsCount());
        $this->assertEquals(65535, $server->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $server->getRemoteExtensions());

        // Create data channels
        $channel = new RTCDataChannel($client, new RTCDataChannelParameters("chat", id: 100));
        $this->assertEquals(100, $channel->getId());
        $this->assertEquals("chat", $channel->getLabel());

        async(function () use ($server, $client) {
            delay(.1);
            $server->stop();
            $client->stop();
        });

        // Attempt to create a second channel with the same ID
        $this->expectException(InvalidArgumentException::class);
        new RTCDataChannel($client, new RTCDataChannelParameters("chat", id: 100));
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testConnectThenClientCreatesNegotiatedDataChannelWithoutId()
    {
        [$client, $server] = $this->createSctpTransport();

        $this->assertFalse($client->isServer());
        $this->assertNull($client->getMaxChannels());
        $this->assertTrue($server->isServer());
        $this->assertNull($server->getMaxChannels());

        $server->start($client->getPort());
        $client->start($server->getPort());

        // Wait async until to connect or fail
        $this->asyncSleep(.01);

        $clientChannels = [];
        $serverChannels = [];
        $this->getTrackChannels($client, $clientChannels);
        $this->getTrackChannels($server, $serverChannels);

        $this->assertEquals(65535, $client->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $client->getState());
        $this->assertEquals(65535, $client->getInboundStreamsCount());
        $this->assertEquals(65535, $client->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $client->getRemoteExtensions());
        $this->assertEquals(65535, $server->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $server->getState());
        $this->assertEquals(65535, $server->getInboundStreamsCount());
        $this->assertEquals(65535, $server->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $server->getRemoteExtensions());

        async(function () use ($server, $client) {
            delay(.1);
            $server->stop();
            $client->stop();
        });

        $this->expectException(InvalidArgumentException::class);
        new RTCDataChannel($client, new RTCDataChannelParameters("chat", negotiated: true));
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testConnectThenClientAndServerCreatesNegotiatedDataChannel()
    {
        [$client, $server] = $this->createSctpTransport();

        $this->assertFalse($client->isServer());
        $this->assertNull($client->getMaxChannels());
        $this->assertTrue($server->isServer());
        $this->assertNull($server->getMaxChannels());

        $server->start($client->getPort());
        $client->start($server->getPort());

        // Wait async until to connect or fail
        $this->asyncSleep(.01);

        $clientChannels = [];
        $serverChannels = [];
        $this->getTrackChannels($client, $clientChannels);
        $this->getTrackChannels($server, $serverChannels);

        $this->assertEquals(65535, $client->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $client->getState());
        $this->assertEquals(65535, $client->getInboundStreamsCount());
        $this->assertEquals(65535, $client->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $client->getRemoteExtensions());
        $this->assertEquals(65535, $server->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $server->getState());
        $this->assertEquals(65535, $server->getInboundStreamsCount());
        $this->assertEquals(65535, $server->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $server->getRemoteExtensions());

        // Create data channels
        $channelClient = new RTCDataChannel($client, new RTCDataChannelParameters("chat", negotiated: true, id: 100));
        $this->assertEquals(100, $channelClient->getId());
        $this->assertEquals("chat", $channelClient->getLabel());

        $channelServer = new RTCDataChannel($server, new RTCDataChannelParameters("chat", negotiated: true, id: 100));
        $this->assertEquals(100, $channelServer->getId());
        $this->assertEquals("chat", $channelServer->getLabel());

        $this->asyncSleep(.01);

        $this->assertEquals(100, $channelClient->getId());
        $this->assertEquals("chat", $channelClient->getLabel());
        $this->assertEquals(100, $channelServer->getId());
        $this->assertEquals("chat", $channelServer->getLabel());
        $this->assertCount(0, $clientChannels);
        $this->assertCount(0, $serverChannels);

        $server->stop();
        $client->stop();
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testConnectThenClientCreatesNegotiatedDataChannelWithUsedId()
    {
        [$client, $server] = $this->createSctpTransport();

        $this->assertFalse($client->isServer());
        $this->assertNull($client->getMaxChannels());
        $this->assertTrue($server->isServer());
        $this->assertNull($server->getMaxChannels());

        $server->start($client->getPort());
        $client->start($server->getPort());

        // Wait async until to connect or fail
        $this->asyncSleep(.01);

        $clientChannels = [];
        $serverChannels = [];
        $this->getTrackChannels($client, $clientChannels);
        $this->getTrackChannels($server, $serverChannels);

        $this->assertEquals(65535, $client->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $client->getState());
        $this->assertEquals(65535, $client->getInboundStreamsCount());
        $this->assertEquals(65535, $client->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $client->getRemoteExtensions());
        $this->assertEquals(65535, $server->getMaxChannels());
        $this->assertEquals(State::ESTABLISHED, $server->getState());
        $this->assertEquals(65535, $server->getInboundStreamsCount());
        $this->assertEquals(65535, $server->getOutboundStreamsCount());
        $this->assertEquals([192, 130], $server->getRemoteExtensions());

        $channelClient = new RTCDataChannel($client, new RTCDataChannelParameters("chat", negotiated: true, id: 100));
        $this->assertEquals(100, $channelClient->getId());
        $this->assertEquals("chat", $channelClient->getLabel());

        async(function () use ($server, $client) {
            delay(.1);
            $server->stop();
            $client->stop();
        });

        $this->expectException(InvalidArgumentException::class);
        new RTCDataChannel($client, new RTCDataChannelParameters("chat", negotiated: true, id: 100));
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testConnectThenClientAndServerCreatesNegotiatedDataChannelBeforeTransport()
    {
        [$client, $server] = $this->createSctpTransport();

        $this->assertFalse($client->isServer());
        $this->assertNull($client->getMaxChannels());
        $this->assertTrue($server->isServer());
        $this->assertNull($server->getMaxChannels());

        $clientChannels = [];
        $serverChannels = [];
        $this->getTrackChannels($client, $clientChannels);
        $this->getTrackChannels($server, $serverChannels);

        $this->assertEquals(State::CLOSED, $client->getState());
        $this->assertEquals(State::CLOSED, $server->getState());

        // Create a data channel for a client
        $channelClient = new RTCDataChannel($client, new RTCDataChannelParameters("chat", negotiated: true, id: 100));
        $this->assertEquals(100, $channelClient->getId());
        $this->assertEquals("chat", $channelClient->getLabel());
        $this->assertEquals(DataChannelState::Connecting, $channelClient->getReadyState());

        // Create data channel for server
        $channelServer = new RTCDataChannel($server, new RTCDataChannelParameters("chat", negotiated: true, id: 100));
        $this->assertEquals(100, $channelServer->getId());
        $this->assertEquals("chat", $channelServer->getLabel());
        $this->assertEquals(DataChannelState::Connecting, $channelServer->getReadyState());

        $server->start($client->getPort());
        $client->start($server->getPort());

        // Wait async until to connect or fail
        $this->asyncSleep(.01);

        $this->assertEquals(State::ESTABLISHED, $client->getState());
        $this->assertEquals(State::ESTABLISHED, $server->getState());
        $this->assertEquals(DataChannelState::Open, $channelClient->getReadyState());
        $this->assertEquals(DataChannelState::Open, $channelServer->getReadyState());

        $this->asyncSleep(.01);

        $this->assertEquals(100, $channelClient->getId());
        $this->assertEquals(100, $channelServer->getId());
        $this->assertCount(0, $clientChannels);
        $this->assertCount(0, $serverChannels);

        $server->stop();
        $client->stop();
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testConnectThenServerCreatesDataChannel()
    {
         // All packets will be delivered
        [$client, $server] = $this->createSctpTransport();

        $server->start($client->getPort());
        $client->start($server->getPort());

        $clientChannels = [];
        $serverChannels = [];
        $this->getTrackChannels($client, $clientChannels);
        $this->getTrackChannels($server, $serverChannels);

        // Wait async until to connect or fail
        $this->asyncSleep(.01);

        $this->assertEquals(State::ESTABLISHED, $client->getState());
        $this->assertEquals([192, 130], $client->getRemoteExtensions());
        $this->assertEquals(State::ESTABLISHED, $server->getState());
        $this->assertEquals([192, 130], $server->getRemoteExtensions());

        // Create data channel
        $channel = new RTCDataChannel($server, new RTCDataChannelParameters("chat"));
        $this->assertNull($channel->getId());
        $this->assertEquals("chat", $channel->getLabel());

        $this->asyncSleep(.01);

        $this->assertCount(1, $clientChannels);
        $this->assertEquals(0, $clientChannels[0]->getId());
        $this->assertEquals("chat", $clientChannels[0]->getLabel());
        $this->assertCount(0, $serverChannels);

        $server->stop();
        $client->stop();
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testConnectWithPartialReliability()
    {
         // All packets will be delivered
        [$client, $server] = $this->createSctpTransport();

        $client->setLocalPartialReliability(true);
        $server->setLocalPartialReliability(false);

        $server->start($client->getPort());
        $client->start($server->getPort());

        // Wait async until to connect or fail
        $this->asyncSleep(.01);

        $this->assertEquals(State::ESTABLISHED, $client->getState());
        $this->assertEquals([130], $client->getRemoteExtensions());
        $this->assertFalse($client->isRemotePartialReliability());

        $this->assertEquals(State::ESTABLISHED, $server->getState());
        $this->assertEquals([192, 130], $server->getRemoteExtensions());
        $this->assertTrue($server->isRemotePartialReliability());

        $server->stop();
        $client->stop();
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testAbruptDisconnect()
    {
        [$client, $server] = $this->createSctpTransport();

        $server->start($client->getPort());
        $client->start($server->getPort());

        // Wait async until to connect or fail
        $this->asyncSleep(.01);

        $this->assertEquals(State::ESTABLISHED, $client->getState());
        $this->assertEquals(State::ESTABLISHED, $server->getState());

        $client->getDtlsTransport()->stop();
        $server->getDtlsTransport()->stop();

        $this->asyncSleep(.01);

        $this->assertEquals(State::CLOSED, $client->getState());
        $this->assertEquals(State::CLOSED, $server->getState());

        $server->stop();
        $client->stop();
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testGarbage()
    {
        [, $server] = $this->createSctpTransport();

        $server->start(5000);

        // Wait async until to connect or fail
        $this->asyncSleep(.01);
        $server->onReceived("garbage");

        $this->assertEquals(State::CONNECTING, $server->getState());

        $server->stop();
    }

    /**
     * @throws RandomException
     * @throws SctpException
     */
    public function testBadVerificationTag()
    {
        $data = file_get_contents(__DIR__ . "/fixture/sctp_init_bad_verification.bin");
        [, $server] = $this->createSctpTransport();

        $server->start(5000);

        // Wait async until to connect or fail
        $this->asyncSleep(.01);
        $server->onReceived($data);

        $this->assertEquals(State::CONNECTING, $server->getState());

        $server->stop();
    }

    public function testMaybeAbandon()
    {
        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null]);
        $client->setLocalTsn(0);
        $client->setRto(0);

        // Send 3 chunks
        $client->sendDataStream(123, 456, str_repeat('M', SctpConstant::USERDATA_MAX_LENGTH * 3));
        $this->assertEquals([0, 1, 2], $this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));

        foreach ($client->getOutboundQueue() as $chunk) {
            $this->assertFalse($chunk->getAttributes()->abandoned);
        }

        // Try to abandon middle chunk
        $client->maybeAbandon($client->getSentQueue()[1]);
        foreach ($client->getOutboundQueue() as $chunk) {
            $this->assertFalse($chunk->getAttributes()->abandoned);
        }
        $client->stop();
    }

    public function testMaybeAbandonMaxRetransmits()
    {
        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null]);
        $client->setRto(0);

        // Set initial TSN values
        $client->setLocalTsn(1);
        $client->setLastSackedTsn(0);
        $client->setAdvancedPeerAckTsn(0);

        // Send 3 chunks
        $client->sendDataStream(123, 456, str_repeat("M", SctpConstant::USERDATA_MAX_LENGTH * 3), maxRetransmits: 0);

        $this->assertEquals([1, 2, 3], $this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));
        $this->assertEquals(4, $client->getLocalTsn());
        $this->assertEquals(0, $client->getAdvancedPeerAckTsn());

        foreach ($client->getOutboundQueue() as $chunk) {
            $this->assertFalse($chunk->isAbandoned());
        }

        // Try to abandon the middle chunk
        $client->maybeAbandon($client->getSentQueue()[1]);

        foreach ($client->getOutboundQueue() as $chunk) {
            $this->assertTrue($chunk->getAttributes()->abandoned);
        }

        // Try to abandon the middle chunk again
        $client->maybeAbandon($client->getSentQueue()[1]);

        foreach ($client->getOutboundQueue() as $chunk) {
            $this->assertTrue($chunk->getAttributes()->abandoned);
        }

        // Update advanced peer ack point
        $client->updateAdvancedPeerAckPoint();

        $this->assertEmpty($this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));
        $this->assertEquals(3, $client->getAdvancedPeerAckTsn());

        // Check forward TSN
        $forwardTsnChunk = $client->getForwardTsnChunk();
        $this->assertNotNull($forwardTsnChunk);
        $this->assertEquals(3, $forwardTsnChunk->getCumulativeTsn());
        $this->assertEquals([[123, 0]], $forwardTsnChunk->getStreams());

        // Transmit
        $client->dataChannelTaskCancel();
        $client->transmit();

        $this->assertNull($client->getForwardTsnChunk());
        $this->assertNotNull($client->getDataChannelTask());

        $client->stop();
    }

    public function testStaleCookie()
    {
        $clientDtls = $this->createDtlsTransportMock(true);
        $serverDtls = $this->createDtlsTransportMock();

        $client = $this->createSctpTransportMockWithMethods([], $clientDtls);
        $server = $this->getMockBuilder(RTCSctpTransport::class)
            ->setConstructorArgs([$serverDtls])
            ->onlyMethods(['getTime'])
            ->getMock();

        $server->method('getTime')
            ->willReturnOnConsecutiveCalls(0, 61);

        $this->connectPairs($clientDtls, $serverDtls, $client, $server);

        $server->start($client->getPort());
        $client->start($server->getPort());

        // Wait async until to connect or fail
        $this->asyncSleep(.01);

        // Outcome check
        $this->assertEquals(State::CLOSED, $client->getState());
        $this->assertEquals(State::CONNECTING, $server->getState());

        $server->stop();
        $client->stop();
    }

    public function testReceiveData()
    {
        $client = $this->createSctpTransportMockWithMethods([]);
        $client->setRto(.1);
        $client->setLastReceivedTsn(0);

        // Receive chunk
        $chunk = new DataChunk(SctpConstant::SCTP_DATA_FIRST_FRAG | SctpConstant::SCTP_DATA_LAST_FRAG);
        $chunk->setUserData("foo");
        $chunk->setTsn(1);
        $client->receiveChunk($chunk);

        $this->assertTrue($client->isSackNeeded());
        $this->assertEmpty($client->getSackDuplicates());
        $this->assertEmpty($client->getSackMisordered());
        $this->assertEquals(1, $client->getLastReceivedTsn());

        $client->setSackNeeded(false);

        // Receive chunk again
        $client->receiveChunk($chunk);
        $this->assertTrue($client->isSackNeeded());
        $this->assertEquals([1], $client->getSackDuplicates());
        $this->assertEmpty($client->getSackMisordered());
        $this->assertEquals(1, $client->getLastReceivedTsn());

        $client->stop();
    }

    public function testReceiveDataOutOfOrder()
    {
        $client = $this->createSctpTransportMockWithMethods([]);
        $client->setLastReceivedTsn(0);

        // Build chunks
        $chunks = [];

        $chunk = new DataChunk(SctpConstant::SCTP_DATA_FIRST_FRAG);
        $chunk->setUserData("foo");
        $chunk->setTsn(1);
        $chunks[] = $chunk;

        $chunk = new DataChunk();
        $chunk->setUserData("bar");
        $chunk->setTsn(2);
        $chunks[] = $chunk;

        $chunk = new DataChunk(SctpConstant::SCTP_DATA_LAST_FRAG);
        $chunk->setUserData("baz");
        $chunk->setTsn(3);
        $chunks[] = $chunk;

        // Receive first chunk
        $client->receiveChunk($chunks[0]);
        $this->assertTrue($client->isSackNeeded());
        $this->assertEmpty($client->getSackDuplicates());
        $this->assertEmpty($client->getSackMisordered());
        $this->assertEquals(1, $client->getLastReceivedTsn());
        $client->setSackNeeded(false);

        // Receive last chunk
        $client->receiveChunk($chunks[2]);
        $this->assertTrue($client->isSackNeeded());
        $this->assertEmpty($client->getSackDuplicates());
        $this->assertEquals([3], $client->getSackMisordered());
        $this->assertEquals(1, $client->getLastReceivedTsn());
        $client->setSackNeeded(false);

        // Receive middle chunk
        $client->receiveChunk($chunks[1]);
        $this->assertTrue($client->isSackNeeded());
        $this->assertEmpty($client->getSackDuplicates());
        $this->assertEmpty($client->getSackMisordered());
        $this->assertEquals(3, $client->getLastReceivedTsn());
        $client->setSackNeeded(false);

        // Receive last chunk again
        $client->receiveChunk($chunks[2]);
        $this->assertTrue($client->isSackNeeded());
        $this->assertEquals([3], $client->getSackDuplicates());
        $this->assertEmpty($client->getSackMisordered());
        $this->assertEquals(3, $client->getLastReceivedTsn());
        $client->setSackNeeded(false);

        $client->stop();
    }

    public function testReceiveForwardTsn()
    {
        $received = [];
        $fakeReceive = function (...$args) use (&$received) {
            $received[] = $args;
        };

        $client = $this->createSctpTransportMockWithMethods(["receive" => null]);
        $client->method("receive")->willReturnCallback($fakeReceive);
        $client->setLastReceivedTsn(101);

        $factory = new DataChunkFactory(102);
        $chunks = array_merge(
            $factory->create(["foo"]),
            $factory->create(["baz"]),
            $factory->create(["qux"]),
            $factory->create(["quux"]),
            $factory->create(["corge"]),
            $factory->create(["grault"])
        );

        // Receive chunks with gaps
        foreach ([0, 2, 3, 5] as $i) {
            $client->receiveChunk($chunks[$i]);
        }

        $this->assertTrue($client->isSackNeeded());
        $this->assertEmpty($client->getSackDuplicates());
        $this->assertEquals([104, 105, 107], $client->getSackMisordered());
        $this->assertEquals(102, $client->getLastReceivedTsn());
        $this->assertEquals([[456, 123, "foo"]], $received);
        $received = [];
        $client->setSackNeeded(false);

        // Receive forward TSN
        $chunk = new ForwardTsnChunk();
        $chunk->setCumulativeTsn(103);
        $chunk->setStreams([[456, 1]]);
        $client->receiveChunk($chunk);
        $this->assertTrue($client->isSackNeeded());
        $this->assertEmpty($client->getSackDuplicates());
        $this->assertEquals([107], $client->getSackMisordered());
        $this->assertEquals(105, $client->getLastReceivedTsn());
        $this->assertEquals([[456, 123, "qux"], [456, 123, "quux"]], $received);
        $received = [];
        $client->setSackNeeded(false);

        // Receive forward TSN again
        $client->receiveChunk($chunk);
        $this->assertTrue($client->isSackNeeded());
        $this->assertEmpty($client->getSackDuplicates());
        $this->assertEquals([107], $client->getSackMisordered());
        $this->assertEquals(105, $client->getLastReceivedTsn());
        $this->assertEmpty($received);
        $client->setSackNeeded(false);

        // Receive chunk
        $client->receiveChunk($chunks[4]);
        $this->assertTrue($client->isSackNeeded());
        $this->assertEmpty($client->getSackDuplicates());
        $this->assertEmpty($client->getSackMisordered());
        $this->assertEquals(107, $client->getLastReceivedTsn());
        $this->assertEquals([[456, 123, "corge"], [456, 123, "grault"]], $received);
        $received = [];
        $client->setSackNeeded(false);

        $client->stop();
    }

    public function testReceiveHeartbeat()
    {
        $ack = null;

        $sendChunkMock = function ($chunk) use (&$ack) {
            $ack = $chunk;
        };
        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null]);
        $client->method("sendChunk")->willReturnCallback($sendChunkMock);

        $chunk = new HeartbeatChunk();
        $chunk->addParams([[1, "\x01\x02\x03\x04"]]);

        $client->receiveChunk($chunk);
        $this->assertInstanceOf(HeartbeatAckChunk::class, $ack);
        $this->assertSame([[1, "\x01\x02\x03\x04"]], $ack->getParams());
    }

    public function testReceiveSackDiscard()
    {
        $client = $this->createSctpTransportMockWithMethods([]);
        $client->setLastReceivedTsn(0);

        $sackPoint = $client->getLastSackedTsn();
        $chunk = new SackChunk();
        $chunk->setCumulativeTsn(SctpUtility::tsnMinusOne($sackPoint));
        $client->receiveChunk($chunk);

        $this->assertSame($sackPoint, $client->getLastSackedTsn());
    }

    public function testReceiveShutdown()
    {
        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null]);
        $client->setLastReceivedTsn(0);
        $client->setState(State::ESTABLISHED);

        // Receive shutdown
        $chunk = new ShutdownChunk();
        $chunk->setCumulativeTsn(SctpUtility::tsnMinusOne($client->getLastSackedTsn()));
        $client->receiveChunk($chunk);
        $this->assertEquals(State::SHUTDOWN_ACK_SENT, $client->getState());

        // Receive shutdown complete
        $chunk = new ShutdownCompleteChunk();
        $client->receiveChunk($chunk);
        $this->assertEquals(State::CLOSED, $client->getState());
    }

    public function testMarkReceived()
    {
        $client = $this->createSctpTransportMockWithMethods([]);
        $client->setLastReceivedTsn(0);

        // Receive 1
        $this->assertFalse($client->markReceived(1));
        $this->assertEquals(1, $client->getLastReceivedTsn());
        $this->assertEmpty($client->getSackMisordered());

        // Receive 3
        $this->assertFalse($client->markReceived(3));
        $this->assertEquals(1, $client->getLastReceivedTsn());
        $this->assertEquals([3], $client->getSackMisordered());

        // Receive 4
        $this->assertFalse($client->markReceived(4));
        $this->assertEquals(1, $client->getLastReceivedTsn());
        $this->assertEquals([3, 4], $client->getSackMisordered());

        // Receive 6
        $this->assertFalse($client->markReceived(6));
        $this->assertEquals(1, $client->getLastReceivedTsn());
        $this->assertEquals([3, 4, 6], $client->getSackMisordered());

        // Receive 2
        $this->assertFalse($client->markReceived(2));
        $this->assertEquals(4, $client->getLastReceivedTsn());
        $this->assertEquals([6], array_values($client->getSackMisordered()));
    }

    public function testSendSack()
    {
        $sack = null;
        $sendChunkMock = function (SackChunk $chunk) use (&$sack) {
            $sack = $chunk;
        };
        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null]);
        $client->method("sendChunk")->willReturnCallback($sendChunkMock);
        $client->setLastReceivedTsn(987);

        $client->sendSack();
        $this->assertNotNull($sack);
        $this->assertEmpty($sack->getDuplicates());
        $this->assertEmpty($sack->getGaps());
        $this->assertEquals(987, $sack->getCumulativeTsn());
    }

    public function testSendSackWithDuplicates()
    {
        $sack = null;
        $sendChunkMock = function (SackChunk $chunk) use (&$sack) {
            $sack = $chunk;
        };
        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null]);
        $client->method("sendChunk")->willReturnCallback($sendChunkMock);
        $client->setLastReceivedTsn(456);
        $client->setSackDuplicates([125, 127]);

        $client->sendSack();
        $this->assertNotNull($sack);
        $this->assertEquals([125, 127], $sack->getDuplicates());
        $this->assertEmpty($sack->getGaps());
        $this->assertEquals(456, $sack->getCumulativeTsn());
    }

    public function testSendSackWithGaps()
    {
        $sack = null;
        $sendChunkMock = function (SackChunk $chunk) use (&$sack) {
            $sack = $chunk;
        };
        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null]);
        $client->method("sendChunk")->willReturnCallback($sendChunkMock);
        $client->setLastReceivedTsn(12);
        $client->setSackMisordered([14, 15, 17]);

        $client->sendSack();
        $this->assertNotNull($sack);
        $this->assertEmpty($sack->getDuplicates());
        $this->assertEquals([[2, 3], [5, 5]], $sack->getGaps());
        $this->assertEquals(12, $sack->getCumulativeTsn());
    }

    public function testSendData()
    {
        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null]);
        $client->setLocalTsn(0);
        $client->setRto(.01);

        // No data
        $client->transmit();
        $this->assertNull($client->getDatachannelTask());
        $this->assertEmpty($this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));
        $this->assertEmpty($client->getOutboundStreamSeq());

        // 1 chunk
        $client->sendDataStream(123, 456, str_repeat('M', SctpConstant::USERDATA_MAX_LENGTH));
        $this->assertNotNull($client->getDatachannelTask());
        $this->assertEquals([0], $this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));
        $this->assertEquals([123 => 1], $client->getOutboundStreamSeq());
        $client->stop();
    }

    public function testSendDataUnordered()
    {
        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null]);
        $client->setRto(.01);
        $client->setLocalTsn(0);

        // 1 chunk
        $client->sendDataStream(123, 456, str_repeat("M", SctpConstant::USERDATA_MAX_LENGTH), ordered: false);
        $this->assertNotNull($client->getDatachannelTask());
        $this->assertEquals([0], $this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));
        $this->assertEmpty($client->getOutboundStreamSeq());
        $client->stop();
    }

    public function testSendDataCongestionControl()
    {
        $sentTsn = [];
        $sendChunkMock = function (DataChunk $chunk) use (&$sentTsn) {
            $sentTsn[] = $chunk->getTsn();
        };

        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null]);
        $client->method("sendChunk")->willReturnCallback($sendChunkMock);

        // Initial setup
        $client->setCwnd(4800);
        $client->setRto(.1);
        $client->setLastSackedTsn(4294967295);
        $client->setLocalTsn(0);
        $client->setSsthresh(4800);

        // Queue 16 chunks, but cwnd only allows 4
        $client->sendDataStream(123, 456, str_repeat("M", SctpConstant::USERDATA_MAX_LENGTH * 16));

        $this->assertEquals(4800, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(4800, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3], $sentTsn);
        $this->assertEquals([0, 1, 2, 3], $this->outstandingTsns($client));
        $this->assertEquals([4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15], $this->queuedTsns($client));

        // SACK comes in acknowledging 2 chunks
        $sack1 = new SackChunk();
        $sack1->setCumulativeTsn(1);
        $client->receiveChunk($sack1);

        $this->assertEquals(6000, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(6000, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6], $sentTsn);
        $this->assertEquals([2, 3, 4, 5, 6], $this->outstandingTsns($client));
        $this->assertEquals([7, 8, 9, 10, 11, 12, 13, 14, 15], $this->queuedTsns($client));

        // SACK comes in acknowledging 2 more chunks
        $sack2 = new SackChunk();
        $sack2->setCumulativeTsn(3);
        $client->receiveChunk($sack2);

        $this->assertEquals(6000, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(6000, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7, 8], $sentTsn);
        $this->assertEquals([4, 5, 6, 7, 8], $this->outstandingTsns($client));
        $this->assertEquals([9, 10, 11, 12, 13, 14, 15], $this->queuedTsns($client));

        // SACK comes in acknowledging 2 more chunks
        $sack3 = new SackChunk();
        $sack3->setCumulativeTsn(5);
        $client->receiveChunk($sack3);

        $this->assertEquals(6000, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(6000, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10], $sentTsn);
        $this->assertEquals([6, 7, 8, 9, 10], $this->outstandingTsns($client));
        $this->assertEquals([11, 12, 13, 14, 15], $this->queuedTsns($client));

        // SACK comes in acknowledging 2 more chunks
        $sack4 = new SackChunk();
        $sack4->setCumulativeTsn(7);
        $client->receiveChunk($sack4);

        $this->assertEquals(7200, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(7200, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13], $sentTsn);
        $this->assertEquals([8, 9, 10, 11, 12, 13], $this->outstandingTsns($client));
        $this->assertEquals([14, 15], $this->queuedTsns($client));

        // SACK comes in acknowledging 2 more chunks
        $sack5 = new SackChunk();
        $sack5->setCumulativeTsn(9);
        $client->receiveChunk($sack5);

        $this->assertEquals(7200, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(7200, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15], $sentTsn);
        $this->assertEquals([10, 11, 12, 13, 14, 15], $this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));
        $client->stop();
    }

    public function testSendDataSlowStart()
    {
        $sentTsn = [];
        $sendChunkMock = function (DataChunk $chunk) use (&$sentTsn) {
            $sentTsn[] = $chunk->getTsn();
        };

        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null]);
        $client->method("sendChunk")->willReturnCallback($sendChunkMock);

        // Initial setup
        $client->setLastSackedTsn(4294967295);
        $client->setLocalTsn(0);
        $client->setSsthresh(131072);

        // Queue 8 chunks, but cwnd only allows 3
        $client->sendDataStream(123, 456, str_repeat("M", SctpConstant::USERDATA_MAX_LENGTH * 8));

        $this->assertEquals(3600, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(3600, $client->getFlightSize());
        $this->assertEquals([0, 1, 2], $sentTsn);
        $this->assertEquals([0, 1, 2], $this->outstandingTsns($client));
        $this->assertEquals([3, 4, 5, 6, 7], $this->queuedTsns($client));

        // SACK comes in acknowledging 2 chunks
        $sack1 = new SackChunk();
        $sack1->setCumulativeTsn(1);
        $client->receiveChunk($sack1);

        $this->assertEquals(4800, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(4800, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5], $sentTsn);
        $this->assertEquals([2, 3, 4, 5], $this->outstandingTsns($client));
        $this->assertEquals([6, 7], $this->queuedTsns($client));

        // SACK comes in acknowledging 2 more chunks
        $sack2 = new SackChunk();
        $sack2->setCumulativeTsn(3);
        $client->receiveChunk($sack2);

        $this->assertEquals(6000, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(4800, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7], $sentTsn);
        $this->assertEquals([4, 5, 6, 7], $this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));

        // SACK comes in acknowledging 2 more chunks
        $sack3 = new SackChunk();
        $sack3->setCumulativeTsn(5);
        $client->receiveChunk($sack3);

        $this->assertEquals(6000, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(2400, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7], $sentTsn);
        $this->assertEquals([6, 7], $this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));

        // SACK comes in acknowledging final chunks
        $sack4 = new SackChunk();
        $sack4->setCumulativeTsn(7);
        $client->receiveChunk($sack4);

        $this->assertEquals(6000, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(0, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7], $sentTsn);
        $this->assertEmpty($this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));
    }

    public function testSendDataWithGaps()
    {
        $sentTsn = [];
        $sendChunkMock = function (DataChunk $chunk) use (&$sentTsn) {
            $sentTsn[] = $chunk->getTsn();
        };

        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null]);
        $client->method("sendChunk")->willReturnCallback($sendChunkMock);

        // Initial setup
        $client->setLastSackedTsn(4294967295);
        $client->setLocalTsn(0);
        $client->setSsthresh(131072);

        // Queue 8 chunks, but cwnd only allows 3
        $client->sendDataStream(123, 456, str_repeat("M", SctpConstant::USERDATA_MAX_LENGTH * 8));

        $this->assertEquals(3600, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(3600, $client->getFlightSize());
        $this->assertEquals([0, 1, 2], $sentTsn);
        $this->assertEquals([0, 1, 2], $this->outstandingTsns($client));
        $this->assertEquals([3, 4, 5, 6, 7], $this->queuedTsns($client));

        // SACK comes in acknowledging chunks 0 and 2 (TSN 1 is missing)
        $sack1 = new SackChunk();
        $sack1->setCumulativeTsn(0);
        $sack1->setGaps([[2, 2]]); // TSN 1 is missing
        $client->receiveChunk($sack1);

        $this->assertEquals(4800, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(4800, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5], $sentTsn);
        $this->assertEquals([1, 2, 3, 4, 5], $this->outstandingTsns($client));
        $this->assertEquals([6, 7], $this->queuedTsns($client));

        // SACK comes in acknowledging chunks 1 and 3
        $sack2 = new SackChunk();
        $sack2->setCumulativeTsn(3);
        $client->receiveChunk($sack2);

        $this->assertEquals(6000, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(4800, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7], $sentTsn);
        $this->assertEquals([4, 5, 6, 7], $this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));

        // SACK comes in acknowledging 2 more chunks
        $sack3 = new SackChunk();
        $sack3->setCumulativeTsn(5);
        $client->receiveChunk($sack3);

        $this->assertEquals(6000, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(2400, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7], $sentTsn);
        $this->assertEquals([6, 7], $this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));

        // SACK comes in acknowledging final chunks
        $sack4 = new SackChunk();
        $sack4->setCumulativeTsn(7);
        $client->receiveChunk($sack4);

        $this->assertEquals(6000, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(0, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7], $sentTsn);
        $this->assertEmpty($this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));
    }

    public function testSendDataWithGap1Retransmit()
    {
        $sentTsn = [];
        $sendChunkMock = function (ChunkInterface $chunk) use (&$sentTsn) {
            if ($chunk instanceof DataChunk) {
                $sentTsn[] = $chunk->getTsn();
            }
        };

        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null]);
        $client->method("sendChunk")->willReturnCallback($sendChunkMock);

        // Initial setup
        $client->setLastSackedTsn(4294967295);
        $client->setLocalTsn(0);
        $client->setSsthresh(131072);

        // Queue 8 chunks, but cwnd only allows 3
        $client->sendDataStream(123, 456, str_repeat("M", SctpConstant::USERDATA_MAX_LENGTH * 8));

        $this->assertEquals(3600, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(3600, $client->getFlightSize());
        $this->assertEquals([0, 1, 2], $sentTsn);
        $this->assertEquals([0, 1, 2], $this->outstandingTsns($client));
        $this->assertEquals([3, 4, 5, 6, 7], $this->queuedTsns($client));

        // SACK comes in acknowledging chunks 0 and 2 (TSN 1 is missing)
        $sack1 = new SackChunk();
        $sack1->setCumulativeTsn(0);
        $sack1->setGaps([[2, 2]]); // TSN 1 is missing
        $client->receiveChunk($sack1);

        $this->assertEquals(4800, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(4800, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5], $sentTsn);
        $this->assertEquals([1, 2, 3, 4, 5], $this->outstandingTsns($client));
        $this->assertEquals([6, 7], $this->queuedTsns($client));

        // SACK comes in acknowledging chunks 3 and 4
        $sack2 = new SackChunk();
        $sack2->setCumulativeTsn(0);
        $sack2->setGaps([[2, 4]]); // TSN 1 is missing
        $client->receiveChunk($sack2);

        $this->assertEquals(4800, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(4800, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7], $sentTsn);
        $this->assertEquals([1, 2, 3, 4, 5, 6, 7], $this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));

        // SACK comes in acknowledging 2 more chunks
        $sack3 = new SackChunk();
        $sack3->setCumulativeTsn(0);
        $sack3->setGaps([[2, 6]]); // TSN 1 is missing
        $client->receiveChunk($sack3);

        $this->assertEquals(4800, $client->getCwnd());
        $this->assertEquals(7, $client->getFastRecoveryExit());
        $this->assertEquals(2400, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7, 1], $sentTsn);
        $this->assertEquals([1, 2, 3, 4, 5, 6, 7], $this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));

        // SACK comes in acknowledging final chunks
        $sack4 = new SackChunk();
        $sack4->setCumulativeTsn(7);
        $client->receiveChunk($sack4);

        $this->assertEquals(4800, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(0, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7, 1], $sentTsn);
        $this->assertEmpty($this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));
    }

    public function testSendDataWithGap2Retransmit()
    {
        $sentTsn = [];
        $sendChunkMock = function (ChunkInterface $chunk) use (&$sentTsn) {
            if ($chunk instanceof DataChunk) {
                $sentTsn[] = $chunk->getTsn();
            }
        };

        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null]);
        $client->method("sendChunk")->willReturnCallback($sendChunkMock);
        $client->setRto(.01);

        // Initial setup
        $client->setLastSackedTsn(4294967295);
        $client->setLocalTsn(0);
        $client->setSsthresh(131072);

        // Queue 8 chunks, but cwnd only allows 3
        $client->sendDataStream(123, 456, str_repeat("M", SctpConstant::USERDATA_MAX_LENGTH * 8));

        $this->assertEquals(3600, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(3600, $client->getFlightSize());
        $this->assertEquals([0, 1, 2], $sentTsn);
        $this->assertEquals([0, 1, 2], $this->outstandingTsns($client));
        $this->assertEquals([3, 4, 5, 6, 7], $this->queuedTsns($client));

        // SACK comes in acknowledging chunk 2 (TSN 0 and 1 are missing)
        $sack1 = new SackChunk();
        $sack1->setCumulativeTsn(4294967295);
        $sack1->setGaps([[3, 3]]); // TSN 0 and 1 are missing
        $client->receiveChunk($sack1);

        $this->assertEquals(3600, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(3600, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3], $sentTsn);
        $this->assertEquals([0, 1, 2, 3], $this->outstandingTsns($client));
        $this->assertEquals([4, 5, 6, 7], $this->queuedTsns($client));

        // SACK comes in acknowledging chunk 3 (TSN 0 and 1 are missing)
        $sack2 = new SackChunk();
        $sack2->setCumulativeTsn(4294967295);
        $sack2->setGaps([[3, 4]]); // TSN 0 and 1 are missing
        $client->receiveChunk($sack2);

        $this->assertEquals(3600, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(3600, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4], $sentTsn);
        $this->assertEquals([0, 1, 2, 3, 4], $this->outstandingTsns($client));
        $this->assertEquals([5, 6, 7], $this->queuedTsns($client));

        // SACK comes in acknowledging chunk 4 (TSN 0 and 1 are missing)
        $sack3 = new SackChunk();
        $sack3->setCumulativeTsn(4294967295);
        $sack3->setGaps([[3, 5]]); // TSN 0 and 1 are missing
        $client->receiveChunk($sack3);

        $this->assertEquals(4800, $client->getCwnd());
        $this->assertEquals(4, $client->getFastRecoveryExit());
        $this->assertEquals(2400, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 0, 1], $sentTsn);
        $this->assertEquals([0, 1, 2, 3, 4], $this->outstandingTsns($client));
        $this->assertEquals([5, 6, 7], $this->queuedTsns($client));

        // SACK comes in acknowledging all chunks up to 4
        $sack4 = new SackChunk();
        $sack4->setCumulativeTsn(4);
        $client->receiveChunk($sack4);

        $this->assertEquals(4800, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(3600, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 0, 1, 5, 6, 7], $sentTsn);
        $this->assertEquals([5, 6, 7], $this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));

        // SACK comes in acknowledging final chunks
        $sack5 = new SackChunk();
        $sack5->setCumulativeTsn(7);
        $client->receiveChunk($sack5);

        $this->assertEquals(4800, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(0, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 0, 1, 5, 6, 7], $sentTsn);
        $this->assertEmpty($this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));
    }

    public function testSendDataWithGap3Retransmit()
    {
        $sentTsn = [];
        $sendChunkMock = function (ChunkInterface $chunk) use (&$sentTsn) {
            if ($chunk instanceof DataChunk) {
                $sentTsn[] = $chunk->getTsn();
            }
        };

        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null]);
        $client->method("sendChunk")->willReturnCallback($sendChunkMock);
        $client->setRto(.01);

        // Initial setup
        $client->setLastSackedTsn(4294967295);
        $client->setLocalTsn(0);
        $client->setSsthresh(131072);

        // Queue 8 chunks, but cwnd only allows 3
        $client->sendDataStream(123, 456, str_repeat("M", SctpConstant::USERDATA_MAX_LENGTH * 8));

        $this->assertEquals(3600, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(3600, $client->getFlightSize());
        $this->assertEquals([0, 1, 2], $sentTsn);
        $this->assertEquals([0, 1, 2], $this->outstandingTsns($client));
        $this->assertEquals([3, 4, 5, 6, 7], $this->queuedTsns($client));

        // SACK comes in acknowledging chunks 0 and 1
        $sack1 = new SackChunk();
        $sack1->setCumulativeTsn(1);
        $client->receiveChunk($sack1);

        $this->assertEquals(4800, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(4800, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5], $sentTsn);
        $this->assertEquals([2, 3, 4, 5], $this->outstandingTsns($client));
        $this->assertEquals([6, 7], $this->queuedTsns($client));

        // SACK comes in acknowledging chunk 5 (TSN 2, 3, and 4 are missing)
        $sack2 = new SackChunk();
        $sack2->setCumulativeTsn(1);
        $sack2->setGaps([[4, 4]]); // TSN 2, 3, and 4 are missing
        $client->receiveChunk($sack2);

        $this->assertEquals(4800, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(4800, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6], $sentTsn);
        $this->assertEquals([2, 3, 4, 5, 6], $this->outstandingTsns($client));
        $this->assertEquals([7], $this->queuedTsns($client));

        // SACK comes in acknowledging chunk 6 (TSN 2, 3, and 4 are missing)
        $sack3 = new SackChunk();
        $sack3->setCumulativeTsn(1);
        $sack3->setGaps([[4, 5]]); // TSN 2, 3, and 4 are missing
        $client->receiveChunk($sack3);

        $this->assertEquals(4800, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(4800, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7], $sentTsn);
        $this->assertEquals([2, 3, 4, 5, 6, 7], $this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));

        // Artificially raise flight size to hit cwnd
        $client->setFlightSize($client->getFlightSize() + 2400);

        // SACK comes in acknowledging chunk 7 (TSN 2, 3, and 4 are missing)
        $sack4 = new SackChunk();
        $sack4->setCumulativeTsn(1);
        $sack4->setGaps([[4, 6]]); // TSN 2, 3, and 4 are missing
        $client->receiveChunk($sack4);

        $this->assertEquals(4800, $client->getCwnd());
        $this->assertEquals(7, $client->getFastRecoveryExit());
        $this->assertEquals(4800, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7, 2, 3], $sentTsn);
        $this->assertEquals([2, 3, 4, 5, 6, 7], $this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));

        // SACK comes in acknowledging all chunks up to 3, and 5, 6, 7 (TSN 4 is missing)
        $sack5 = new SackChunk();
        $sack5->setCumulativeTsn(3);
        $sack5->setGaps([[2, 4]]); // TSN 4 is missing
        $client->receiveChunk($sack5);

        $this->assertEquals(4800, $client->getCwnd());
        $this->assertEquals(7, $client->getFastRecoveryExit());
        $this->assertEquals(3600, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7, 2, 3, 4], $sentTsn);
        $this->assertEquals([4, 5, 6, 7], $this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));

        // SACK comes in acknowledging all chunks
        $sack6 = new SackChunk();
        $sack6->setCumulativeTsn(7);
        $client->receiveChunk($sack6);

        $this->assertEquals(4800, $client->getCwnd());
        $this->assertNull($client->getFastRecoveryExit());
        $this->assertEquals(2400, $client->getFlightSize());
        $this->assertEquals([0, 1, 2, 3, 4, 5, 6, 7, 2, 3, 4], $sentTsn);
        $this->assertEmpty($this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));
    }

    public function testTimer2ExpiredWhenShutdownAckSent()
    {
        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null, "transmit" => null]);
        $client->setRto(.01);

        $chunk = new ShutdownAckChunk();

        // Fails once
        $client->setState(State::SHUTDOWN_ACK_SENT);
        $client->getTimer2()->start($chunk);
        $client->getTimer2()->expired();

        $this->assertEquals(1, $client->getTimer2()->getFailures());
        $this->assertNotNull($client->getTimer2()->getTask());
        $this->assertEquals(State::SHUTDOWN_ACK_SENT, $client->getState());

        // Fails 10 times
        $client->getTimer2()->setFailures(9);
        $client->getTimer2()->expired();

        $this->assertEquals(10, $client->getTimer2()->getFailures());
        $this->assertNotNull($client->getTimer2()->getTask());
        $this->assertEquals(State::SHUTDOWN_ACK_SENT, $client->getState());

        // Fails 11 times
        $client->getTimer2()->expired();

        $this->assertEquals(11, $client->getTimer2()->getFailures());
        $this->assertNull($client->getTimer2()->getTask());
        $this->assertEquals(State::CLOSED, $client->getState());
    }

    public function testDataChannelTimerExpired()
    {
        $client = $this->createSctpTransportMockWithMethods(["sendChunk" => null]);
        $client->setLocalTsn(0);
        $client->setRto(.01);

        // 1 chunk
        $client->sendDataStream(123, 456, str_repeat("M", SctpConstant::USERDATA_MAX_LENGTH));

        $this->assertNotNull($client->getDataChannelTask());
        $this->assertEquals([0], $this->outstandingTsns($client));
        $this->assertEmpty($this->queuedTsns($client));

        // T3 expires
        // FIXME
//        $client->method("transmit");
//        $client->dataChannelTaskExpired();
//
//        $this->assertNull($client->getDataChannelTask());
//        $this->assertEquals([0], $this->outstandingTsns($client));
//        $this->assertEmpty($this->queuedTsns($client));

        // Verify a retransmit flag is set for all chunks in the outbound queue
        foreach ($client->getOutboundQueue() as $chunk) {
            $this->assertTrue($chunk->getRetransmit());
        }
        $client->dataChannelTaskCancel();
        $client->getTimer2()->cancel();
        $client->getTimer1()->cancel();
        $client->stop();
    }

    /**
     * @return RTCSctpTransport[]
     * @throws SctpException
     * @throws RandomException
     */
    private function createSctpTransport(): array
    {
        $clientDtls = $this->createDtlsTransportMock(true);
        $serverDtls = $this->createDtlsTransportMock();

        $client = new RTCSctpTransport($clientDtls);
        $server = new RTCSctpTransport($serverDtls);

        $this->connectPairs($clientDtls, $serverDtls, $client, $server);

        return [$client, $server];
    }

    private function createDtlsTransportMock(bool $client = false): RTCDtlsTransportMock | MockObject
    {
        // Create the ICE transport mock
        $iceTransport = $this->getMockBuilder(RTCIceTransport::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRole'])
            ->getMock();

        $iceTransport->expects($this->any())
            ->method('getRole')
            ->willReturn($client ? IceRole::Controlling : IceRole::Controlled);

        // Create the DTLS transport mock
        $dtlsTransportMock = $this->getMockBuilder(RTCDtlsTransportMock::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getState',
                'getIceTransport',
                'setSctpReceiver',
                'removeSctpReceiver',
                'sendData',
                'stop'
            ])
            ->getMock();

        $dtlsTransportMock->expects($this->any())
            ->method('getState')
            ->willReturn(TLSState::CONNECTED);

        $dtlsTransportMock->expects($this->any())
            ->method('getIceTransport')
            ->willReturn($iceTransport);

        $dtlsTransportMock->expects($this->any())
            ->method('setSctpReceiver');

        $dtlsTransportMock->expects($this->any())
            ->method('removeSctpReceiver');

        return $dtlsTransportMock;
    }

    private function connectPairs(
        RTCDtlsTransportMock|MockObject $clientDtls,
        RTCDtlsTransportMock|MockObject $serverDtls,
        RTCSctpTransport $client,
        RTCSctpTransport $server
    ): void {
        $probability = new Probability($this->lossProbability);

        $serverDtls->expects($this->any())
            ->method('sendData')
            ->willReturnCallback(function (...$args) use ($client, $probability) {
                if (!$probability->probabilityHappen()) {
                    $this->receivedClientQueue->enqueue($args[0]);
                    $client->onReceived($args[0]);
                }
                return true;
            });

        $clientDtls->expects($this->any())
            ->method('sendData')
            ->willReturnCallback(function (...$args) use ($server, $probability) {
                if (!$probability->probabilityHappen()) {
                    $this->receivedServerQueue->enqueue($args[0]);
                    $server->onReceived($args[0]);
                }
                return true;
            });

        $serverDtls->expects($this->any())
            ->method('stop')
            ->willReturnCallback(function () use ($server) {
                $server->stop();
            });

        $clientDtls->expects($this->any())
            ->method('stop')
            ->willReturnCallback(function () use ($client) {
                $client->stop();
            });
    }

    private function asyncSleep(float $seconds): void
    {
        delay($seconds);
    }

    private function getLatestDataChunk(): array
    {
        $dataChunks = [];
        while (!$this->receivedServerQueue->isEmpty()) {
            $chunk = SctpPacket::decode($this->receivedServerQueue->dequeue())[3][0];
            if ($chunk instanceof DataChunk) {
                $dataChunks [] = [$chunk->getStreamId(), $chunk->getProtocol(), $chunk->getUserData()];
            }
        }

        return $dataChunks;
    }

    private function getTrackChannels(RTCSctpTransport $transport, array &$channels): void
    {
        $transport->on("datachannel", function (...$args) use (&$channels) {
            $channels [] = $args[0];
        });
    }

    private function outstandingTsns(RTCSctpTransport $transport): array
    {
        $tsns = [];
        $queue = $transport->getSentQueue();

        foreach ($queue as $chunk) {
            if ($chunk instanceof DataChunk) {
                $tsns[] = $chunk->getTsn();
            }
        }

        return $tsns;
    }

    private function queuedTsns(RTCSctpTransport $transport): array
    {
        $tsns = [];
        $queue = $transport->getOutboundQueue();

        foreach ($queue as $chunk) {
            if ($chunk instanceof DataChunk) {
                $tsns[] = $chunk->getTsn();
            }
        }

        return $tsns;
    }

    public function createSctpTransportMockWithMethods(array $methods, ?RTCDtlsTransportMock $dtlsTransport = null, bool $client = true, ?array $manipulateMethods = null): RTCSctpTransport|MockObject
    {
        $transport = $this->getMockBuilder(RTCSctpTransport::class)
            ->setConstructorArgs([$dtlsTransport ?? $this->createDtlsTransportMock($client)])
            ->onlyMethods($manipulateMethods ?? array_keys($methods))
            ->getMock();

        foreach ($methods as $method => $return) {
            $return ? $transport->method($method)->willReturn($return) : $transport->method($method);
        }

        return $transport;
    }
}

