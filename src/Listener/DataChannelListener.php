<?php

namespace Webrtc\SCTP\Listener;

use Webrtc\DataChannel\RTCDataChannel;

/**
 * Notified when an {@see \Webrtc\SCTP\RTCSctpTransport} opens a remotely-initiated data channel,
 * replacing the former Evenement "datachannel" event.
 */
interface DataChannelListener
{
    public function onDataChannel(RTCDataChannel $channel): void;
}
