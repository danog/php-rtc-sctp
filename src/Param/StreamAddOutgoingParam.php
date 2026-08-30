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
 * Represents a Stream Add Outgoing Parameter.
 */
readonly final class StreamAddOutgoingParam implements StreamParamInterface
{
    /**
     * StreamAddOutgoingParam constructor.
     *
     * @param int $requestSequence Request sequence number.
     * @param int $newStreams Number of new streams.
     */
    public function __construct(
        private int $requestSequence,
        private int $newStreams
    ) {
    }

    /**
     * Encodes the parameter into a binary string.
     *
     * @return string Binary representation of the parameter.
     */
    #[Override]
    public function encode(): string
    {
        return pack("Nnn", $this->requestSequence, $this->newStreams, 0);
    }

    /**
     * Decodes a binary string into a StreamAddOutgoingParam object.
     *
     * @param string $data Binary string to decode.
     * @return StreamAddOutgoingParam Decoded object.
     */
    #[Override]
    public static function decode(string $data): self
    {
        $unpacked = unpack("NrequestSequence/nnewStreams/nreserved", $data);
        if ($unpacked === false) {
            throw new InvalidArgumentException("Failed to unpack Stream Add Outgoing parameter");
        }
        return new self((int) $unpacked["requestSequence"], (int) $unpacked["newStreams"]);
    }

    public function getNewStreams(): int
    {
        return $this->newStreams;
    }

    public function getRequestSequence(): int
    {
        return $this->requestSequence;
    }

    public function __toString(): string
    {
        return sprintf("StreamAddOutgoingParam requestSequence: %d newStreams: %d", $this->requestSequence, $this->newStreams);
    }
}
