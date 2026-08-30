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
 * Represents a Stream Reset Response Parameter.
 */
readonly final class StreamResetResponseParam implements StreamParamInterface
{
    /**
     * StreamResetResponseParam constructor.
     *
     * @param int $responseSequence Response sequence number.
     * @param int $result Result code.
     */
    public function __construct(
        private int $responseSequence,
        private int $result
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
        return pack("NN", $this->responseSequence, $this->result);
    }

    /**
     * Decodes a binary string into a StreamResetResponseParam object.
     *
     * @param string $data Binary string to decode.
     * @return StreamResetResponseParam Decoded object.
     */
    #[Override]
    public static function decode(string $data): self
    {
        $unpacked = unpack("NresponseSequence/Nresult", $data);
        if ($unpacked === false) {
            throw new InvalidArgumentException("Failed to unpack Stream Reset Response parameter");
        }
        return new self((int) $unpacked["responseSequence"], (int) $unpacked["result"]);
    }

    public function getResponseSequence(): int
    {
        return $this->responseSequence;
    }

    public function getResult(): int
    {
        return $this->result;
    }

    public function __toString(): string
    {
        return sprintf(
            "StreamResetResponseParam responseSequence: %d result:%d",
            $this->responseSequence,
            $this->result
        );
    }
}
