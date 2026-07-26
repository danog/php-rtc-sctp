<?php declare(strict_types=1);

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SCTP;

class SctpConstant
{
    // Local constants
    public const COOKIE_LENGTH = 24;
    public const COOKIE_LIFETIME = 60;
    public const MAX_STREAMS = 65535;
    public const USERDATA_MAX_LENGTH = 1200;

    // Protocol constants
    public const SCTP_CAUSE_INVALID_STREAM = 0x0001;
    public const SCTP_CAUSE_STALE_COOKIE = 0x0003;

    public const SCTP_DATA_LAST_FRAG = 0x01;
    public const SCTP_DATA_FIRST_FRAG = 0x02;
    public const SCTP_DATA_UNORDERED = 0x04;

    public const SCTP_MAX_ASSOCIATION_RETRANS = 10;
    public const SCTP_MAX_BURST = 4;
    public const SCTP_MAX_INIT_RETRANS = 8;
    public const SCTP_RTO_ALPHA = 1 / 8;
    public const SCTP_RTO_BETA = 1 / 4;
    public const SCTP_RTO_INITIAL = 3.0;
    public const SCTP_RTO_MIN = 1;
    public const SCTP_RTO_MAX = 60;
    public const SCTP_TSN_MODULO = 2 ** 32;

    public const RECONFIG_MAX_STREAMS = 135;

    // Parameters
    public const SCTP_STATE_COOKIE = 0x0007;
    public const SCTP_STR_RESET_OUT_REQUEST = 0x000D;
    public const SCTP_STR_RESET_RESPONSE = 0x0010;
    public const SCTP_STR_RESET_ADD_OUT_STREAMS = 0x0011;
    public const SCTP_SUPPORTED_CHUNK_EXT = 0x8008;
    public const SCTP_PRSCTP_SUPPORTED = 0xC000;

    // Data channel constants
    public const DATA_CHANNEL_ACK = 2;
    public const DATA_CHANNEL_OPEN = 3;

    public const DATA_CHANNEL_RELIABLE = 0x00;
    public const DATA_CHANNEL_PARTIAL_RELIABLE_REXMIT = 0x01;
    public const DATA_CHANNEL_PARTIAL_RELIABLE_TIMED = 0x02;
    public const DATA_CHANNEL_RELIABLE_UNORDERED = 0x80;
    public const DATA_CHANNEL_PARTIAL_RELIABLE_REXMIT_UNORDERED = 0x81;
    public const DATA_CHANNEL_PARTIAL_RELIABLE_TIMED_UNORDERED = 0x82;

    public const WEBRTC_DCEP = 50;
    public const WEBRTC_STRING = 51;
    public const WEBRTC_BINARY = 53;
    public const WEBRTC_STRING_EMPTY = 56;
    public const WEBRTC_BINARY_EMPTY = 57;
}
