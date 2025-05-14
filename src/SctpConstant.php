<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SCTP;

class SctpConstant {
    // Local constants
    const int COOKIE_LENGTH = 24;
    const int COOKIE_LIFETIME = 60;
    const int MAX_STREAMS = 65535;
    const int USERDATA_MAX_LENGTH = 1200;

    // Protocol constants
    const int SCTP_CAUSE_INVALID_STREAM = 0x0001;
    const int SCTP_CAUSE_STALE_COOKIE = 0x0003;

    const int SCTP_DATA_LAST_FRAG = 0x01;
    const int SCTP_DATA_FIRST_FRAG = 0x02;
    const int SCTP_DATA_UNORDERED = 0x04;

    const int SCTP_MAX_ASSOCIATION_RETRANS = 10;
    const int SCTP_MAX_BURST = 4;
    const int SCTP_MAX_INIT_RETRANS = 8;
    const int|float SCTP_RTO_ALPHA = 1 / 8;
    const int|float SCTP_RTO_BETA = 1 / 4;
    const float SCTP_RTO_INITIAL = 3.0;
    const int SCTP_RTO_MIN = 1;
    const int SCTP_RTO_MAX = 60;
    const int SCTP_TSN_MODULO = 2 ** 32;

    const int RECONFIG_MAX_STREAMS = 135;

    // Parameters
    const int SCTP_STATE_COOKIE = 0x0007;
    const int SCTP_STR_RESET_OUT_REQUEST = 0x000D;
    const int SCTP_STR_RESET_RESPONSE = 0x0010;
    const int SCTP_STR_RESET_ADD_OUT_STREAMS = 0x0011;
    const int SCTP_SUPPORTED_CHUNK_EXT = 0x8008;
    const int SCTP_PRSCTP_SUPPORTED = 0xC000;

    // Data channel constants
    const int DATA_CHANNEL_ACK = 2;
    const int DATA_CHANNEL_OPEN = 3;

    const int DATA_CHANNEL_RELIABLE = 0x00;
    const int DATA_CHANNEL_PARTIAL_RELIABLE_REXMIT = 0x01;
    const int DATA_CHANNEL_PARTIAL_RELIABLE_TIMED = 0x02;
    const int DATA_CHANNEL_RELIABLE_UNORDERED = 0x80;
    const int DATA_CHANNEL_PARTIAL_RELIABLE_REXMIT_UNORDERED = 0x81;
    const int DATA_CHANNEL_PARTIAL_RELIABLE_TIMED_UNORDERED = 0x82;

    const int WEBRTC_DCEP = 50;
    const int WEBRTC_STRING = 51;
    const int WEBRTC_BINARY = 53;
    const int WEBRTC_STRING_EMPTY = 56;
    const int WEBRTC_BINARY_EMPTY = 57;
}