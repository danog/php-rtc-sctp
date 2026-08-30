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
 * Selective Acknowledgment chunk.
 */
final class SackChunk extends Chunk
{
    /** @var int Chunk type identifier. */
    protected int $type = 3;

    /** @var int Cumulative TSN. */
    protected int $cumulativeTsn;

    /** @var int Advertised receiver window. */
    protected int $advertisedRwnd;

    /** @var array<array-key, array{0: int, 1: int}> List of gaps. */
    protected array $gaps = [];

    /** @var array<int> List of duplicate TSNs. */
    protected array $duplicates = [];

    /**
     * SackChunk constructor.
     *
     * @param int $flags Chunk flags.
     * @param string|null $body Chunk body.
     */
    public function __construct(int $flags = 0, ?string $body = null)
    {
        parent::__construct($flags);
        if ($body !== null && $body !== "") {
            $unpacked = unpack("NcumulativeTsn/NadvertisedRwnd/ngaps/nduplicates", substr($body, 0, 12));
            if ($unpacked === false) {
                throw new InvalidArgumentException("Failed to unpack SACK chunk body");
            }
            $this->cumulativeTsn = (int) $unpacked["cumulativeTsn"];
            $this->advertisedRwnd = (int) $unpacked["advertisedRwnd"];
            $pos = 12;
            for ($i = 0; $i < (int) $unpacked["gaps"]; $i++) {
                $gap = unpack("nstart/nend", substr($body, $pos, 4));
                if ($gap === false) {
                    throw new InvalidArgumentException("Failed to unpack SACK gap");
                }
                $this->gaps[] = [0 => (int) $gap["start"], 1 => (int) $gap["end"]];
                $pos += 4;
            }
            for ($i = 0; $i < (int) $unpacked["duplicates"]; $i++) {
                $dup = unpack("N", substr($body, $pos, 4));
                if ($dup === false) {
                    throw new InvalidArgumentException("Failed to unpack SACK duplicate");
                }
                $this->duplicates[] = (int) $dup[1];
                $pos += 4;
            }
        } else {
            $this->cumulativeTsn = 0;
            $this->advertisedRwnd = 0;
        }
    }

    /**
     * Encodes the chunk into a binary string.
     *
     * @return string Binary representation of the chunk.
     */
    #[Override]
    public function encode(): string
    {
        $length = 16 + 4 * (\count($this->gaps) + \count($this->duplicates));
        $data = pack("CCnNNnn", $this->type, $this->flags, $length, $this->cumulativeTsn, $this->advertisedRwnd, \count($this->gaps), \count($this->duplicates));
        foreach ($this->gaps as [$start, $end]) {
            $data .= pack("nn", $start, $end);
        }
        foreach ($this->duplicates as $tsn) {
            $data .= pack("N", $tsn);
        }
        return $data;
    }

    /**
     * Returns a string representation of the chunk.
     *
     * @return string String representation.
     */
    public function __toString(): string
    {
        return sprintf(
            "SackChunk(flags=%d, advertisedRwnd=%d, cumulativeTsn=%d, gaps=%s)",
            $this->flags,
            $this->advertisedRwnd,
            $this->cumulativeTsn,
            (string) json_encode($this->gaps)
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
    public function getGaps(): array
    {
        return $this->gaps;
    }

    /** @param array<array-key, array{0: int, 1: int}> $gaps */
    public function setGaps(array $gaps): void
    {
        $this->gaps = $gaps;
    }

    public function getAdvertisedRwnd(): int
    {
        return $this->advertisedRwnd;
    }

    public function setAdvertisedRwnd(int $advertisedRwnd): void
    {
        $this->advertisedRwnd = $advertisedRwnd;
    }

    /** @return array<int> */
    public function getDuplicates(): array
    {
        return $this->duplicates;
    }

    /** @param array<int> $duplicates */
    public function setDuplicates(array $duplicates): void
    {
        $this->duplicates = $duplicates;
    }
}
