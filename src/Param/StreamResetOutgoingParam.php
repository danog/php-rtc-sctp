<?php declare(strict_types=1);

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SCTP\Param;

use Override;
use Webrtc\Exception\InvalidArgumentException;

/**
 * Represents a Stream Reset Outgoing Parameter.
 */
readonly final class StreamResetOutgoingParam implements StreamParamInterface
{
    /**
     * StreamResetOutgoingParam constructor.
     *
     * @param int $requestSequence Request sequence number.
     * @param int $responseSequence Response sequence number.
     * @param int $lastTsn Last Transmission Sequence Number (TSN).
     * @param array<int> $streams List of streams.
     */
    public function __construct(
        private int   $requestSequence,
        private int   $responseSequence,
        private int   $lastTsn,
        private array $streams = []
    ) {
    }

    /** @return array<int> */
    public function getStreams(): array
    {
        return $this->streams;
    }

    /**
     * Encodes the parameter into a binary string.
     *
     * @return string Binary representation of the parameter.
     */
    #[Override]
    public function encode(): string
    {
        $data = pack("NNN", $this->requestSequence, $this->responseSequence, $this->lastTsn);
        foreach ($this->streams as $stream) {
            $data .= pack("n", $stream);
        }
        return $data;
    }

    /**
     * Decodes a binary string into a StreamResetOutgoingParam object.
     *
     * @param string $data Binary string to decode.
     * @return StreamResetOutgoingParam Decoded object.
     */
    #[Override]
    public static function decode(string $data): self
    {
        $unpacked = unpack("NrequestSequence/NresponseSequence/NlastTsn", substr($data, 0, 12));
        if ($unpacked === false) {
            throw new InvalidArgumentException("Failed to unpack Stream Reset Outgoing parameter");
        }
        $streams = [];
        for ($pos = 12; $pos < \strlen($data); $pos += 2) {
            $stream = unpack("n", substr($data, $pos, 2));
            if ($stream === false) {
                throw new InvalidArgumentException("Failed to unpack Stream Reset Outgoing stream");
            }
            $streams[] = (int) $stream[1];
        }
        return new self(
            (int) $unpacked["requestSequence"],
            (int) $unpacked["responseSequence"],
            (int) $unpacked["lastTsn"],
            $streams
        );
    }

    public function getRequestSequence(): int
    {
        return $this->requestSequence;
    }

    public function getResponseSequence(): int
    {
        return $this->responseSequence;
    }

    public function getLastTsn(): int
    {
        return $this->lastTsn;
    }

    public function __toString(): string
    {
        return sprintf(
            "StreamResetOutgoingParam requestSequence: %d, responseSequence: %d, lastTsn: %d streams: %d",
            $this->requestSequence,
            $this->responseSequence,
            $this->lastTsn,
            \count($this->streams)
        );
    }
}
