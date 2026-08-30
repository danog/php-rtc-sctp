<?php declare(strict_types=1);

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SCTP\Chunk;

use Override;
use Webrtc\Exception\InvalidArgumentException;

/**
 * Forward TSN chunk.
 */
final class ForwardTsnChunk extends Chunk
{
    /** @var int Chunk type identifier. */
    protected int $type = 192;

    /** @var int Cumulative TSN. */
    protected int $cumulativeTsn;

    /** @var array<array-key, array{0: int, 1: int}> List of streams. */
    protected array $streams = [];

    /**
     * ForwardTsnChunk constructor.
     *
     * @param int $flags Chunk flags.
     * @param string|null $body Chunk body.
     */
    public function __construct(int $flags = 0, ?string $body = null)
    {
        parent::__construct($flags);
        if ($body !== null && $body !== "") {
            $cumulative = unpack("N", substr($body, 0, 4));
            if ($cumulative === false) {
                throw new InvalidArgumentException("Failed to unpack FORWARD TSN cumulative TSN");
            }
            $this->cumulativeTsn = (int) $cumulative[1];
            $pos = 4;
            while ($pos < \strlen($body)) {
                $stream = unpack("nstreamId/nstreamSeq", substr($body, $pos, 4));
                if ($stream === false) {
                    throw new InvalidArgumentException("Failed to unpack FORWARD TSN stream");
                }
                $this->streams[] = [0 => (int) $stream["streamId"], 1 => (int) $stream["streamSeq"]];
                $pos += 4;
            }
        } else {
            $this->cumulativeTsn = 0;
        }
    }

    /**
     * Gets the encoded body of the chunk.
     *
     * @return string Encoded body.
     */
    #[Override]
    public function getBody(): string
    {
        $body = pack("N", $this->cumulativeTsn);
        foreach ($this->streams as [$streamId, $streamSeq]) {
            $body .= pack("nn", $streamId, $streamSeq);
        }
        return $body;
    }

    /**
     * Returns a string representation of the chunk.
     *
     * @return string String representation.
     */
    public function __toString(): string
    {
        return sprintf(
            "ForwardTsnChunk(cumulativeTsn=%d, streams=%s)",
            $this->cumulativeTsn,
            (string) json_encode($this->streams)
        );
    }

    public function getCumulativeTsn(): int
    {
        return $this->cumulativeTsn;
    }

    public function setCumulativeTsn(int $cumulativeTsn): void
    {
        $this->cumulativeTsn = $cumulativeTsn;
    }

    /** @return array<array-key, array{0: int, 1: int}> */
    public function getStreams(): array
    {
        return $this->streams;
    }

    /** @param array<array-key, array{0: int, 1: int}> $streams */
    public function setStreams(array $streams): void
    {
        $this->streams = $streams;
    }
}
