<?php

namespace Tests\Webrtc\SCTP;

use Webrtc\DataChannel\RTCSctpTransportInterface;
use Webrtc\ICE\RTCIceTransport;
use Webrtc\ICE\RTCIceTransportInterface;
use Webrtc\SCTP\RTCSctpDtlsTransportInterface;
use Webrtc\SCTP\RTCSctpTransport;
use Webrtc\Stats\enum\TLSState;

class RTCDtlsTransportMock implements RTCSctpDtlsTransportInterface
{

    public function getState(): TLSState
    {
        return TLSState::CONNECTED;
    }

    public function getIceTransport(): RTCIceTransportInterface
    {
        return new RTCIceTransport();
    }

    public function setSctpReceiver(?RTCSctpTransportInterface $sctpReceiver = null): void
    {
        // TODO: Implement setSctpReceiver() method.
    }

    public function removeSctpReceiver(RTCSctpTransport $param): void
    {
        // TODO: Implement removeSctpReceiver() method.
    }

    public function sendData(string $data): void
    {
        // TODO: Implement sendData() method.
    }

    public function stop(): void
    {
        // TODO: Implement sendData() method.
    }
}