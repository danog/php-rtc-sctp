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

use Webrtc\Mixin\DataClass;

#[DataClass]
final class AttributeChunk
{
    /**
     * Attrs of chunk.
     *
     */
    public function __construct(
        public int $bookSize = 0,
        public float $expiry = 0,
        public ?int $maxRetransmits = null,
        public bool $abandoned = false,
        public bool $acked = false,
        public int $misses = 0,
        public bool $retransmit = false,
        public int $sentCount = 0,
        public ?float $sentTime = null,
    ) {
    }
}
