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

use Webrtc\Exception\InvalidArgumentException;
use Webrtc\SCTP\Chunk\AbortChunk;
use Webrtc\SCTP\Chunk\Chunk;
use Webrtc\SCTP\Chunk\ChunkInterface;
use Webrtc\SCTP\Chunk\CookieAckChunk;
use Webrtc\SCTP\Chunk\CookieEchoChunk;
use Webrtc\SCTP\Chunk\DataChunk;
use Webrtc\SCTP\Chunk\ErrorChunk;
use Webrtc\SCTP\Chunk\ForwardTsnChunk;
use Webrtc\SCTP\Chunk\HeartbeatAckChunk;
use Webrtc\SCTP\Chunk\HeartbeatChunk;
use Webrtc\SCTP\Chunk\InitAckChunk;
use Webrtc\SCTP\Chunk\InitChunk;
use Webrtc\SCTP\Chunk\ReconfigChunk;
use Webrtc\SCTP\Chunk\SackChunk;
use Webrtc\SCTP\Chunk\ShutdownAckChunk;
use Webrtc\SCTP\Chunk\ShutdownChunk;
use Webrtc\SCTP\Chunk\ShutdownCompleteChunk;

/**
 * SCTP Packet handling class.
 *
 * This class provides functionality to encode and decode Stream Control Transmission Protocol (SCTP) packets.
 * SCTP is a transport layer protocol that provides reliable, message-oriented communication with congestion control.
 *
 * The class handles:
 * - Parsing raw binary SCTP packets into their components (header fields and chunks)
 * - Constructing SCTP packets from individual components
 * - Validating packet integrity through checksum verification
 * - Supporting all standard SCTP chunk types as defined in RFC 4960
 */
final class SctpPacket
{
    /**
     * Mapping of SCTP chunk type codes to their corresponding class names.
     *
     * @var array<int, string> CHUNK_TYPES
     */
    public const CHUNK_TYPES = [
        0 => "DataChunk",
        1 => "InitChunk",
        2 => "InitAckChunk",
        3 => "SackChunk",
        4 => "HeartbeatChunk",
        5 => "HeartbeatAckChunk",
        6 => "AbortChunk",
        7 => "ShutdownChunk",
        8 => "ShutdownAckChunk",
        9 => "ErrorChunk",
        10 => "CookieEchoChunk",
        11 => "CookieAckChunk",
        14 => "ShutdownCompleteChunk",
        130 => "ReconfigChunk",
        192 => "ForwardTsnChunk",
    ];

    /**
     * Decodes a binary SCTP packet into its constituent parts.
     *
     * Parses the packet header and all chunks, validates the checksum, and returns
     * the packet components as an array. Throw an exception if the packet is invalid.
     *
     * @param string $data The binary string representing the SCTP packet.
     * @return array{
     *     0: int, // Source port
     *     1: int, // Destination port
     *     2: int, // Verification tag
     *     3: ChunkInterface[] // Array of chunk objects
     * }
     * @throws InvalidArgumentException If:
     *         - Packet length is less than 12 bytes (minimum SCTP header size)
     *         - Checksum validation fails
     *         - Chunk parsing fails
     */
    public static function decode(string $data): array
    {
        $length = \strlen($data);
        if ($length < 12) {
            throw new InvalidArgumentException("SCTP packet length is less than 12 bytes");
        }

        $unpacked = unpack("nsourcePort/ndestinationPort/NverificationTag", $data);
        if ($unpacked === false) {
            throw new InvalidArgumentException("SCTP packet header could not be unpacked");
        }
        $sourcePort = (int) $unpacked['sourcePort'];
        $destinationPort = (int) $unpacked['destinationPort'];
        $verificationTag = (int) $unpacked['verificationTag'];

        // Verify checksum
        $checksumData = unpack("V", substr($data, 8, 4));
        if ($checksumData === false) {
            throw new InvalidArgumentException("SCTP checksum could not be unpacked");
        }
        $checksum = (int) $checksumData[1];
        if ($checksum !== SctpUtility::crc32c(substr($data, 0, 8) . "\x00\x00\x00\x00" . substr($data, 12))) {
            throw new InvalidArgumentException("SCTP packet has invalid checksum");
        }

        $chunks = [];
        $pos = 12;
        while ($pos <= $length - 4) {
            $chunkHeader = unpack("Ctype/Cflags/nlength", substr($data, $pos, 4));
            if ($chunkHeader === false) {
                throw new InvalidArgumentException("SCTP chunk header could not be unpacked");
            }
            $chunkType = (int) $chunkHeader['type'];
            $chunkFlags = (int) $chunkHeader['flags'];
            $chunkLength = (int) $chunkHeader['length'];

            $chunkBody = substr($data, $pos + 4, $chunkLength - 4);
            if (isset(self::CHUNK_TYPES[$chunkType])) {
                $chunk = self::chunkFromType($chunkType, $chunkFlags, $chunkBody);
                if ($chunk !== null) {
                    $chunks[] = $chunk;
                }
            }

            $pos += $chunkLength + SctpUtility::padl($chunkLength);
        }

        return [$sourcePort, $destinationPort, $verificationTag, $chunks];
    }

    /**
     * Instantiates an SCTP chunk object for the given chunk type.
     *
     * @param int $type The SCTP chunk type code.
     * @param int $flags The chunk flags.
     * @param string $body The chunk body.
     * @return ChunkInterface|null The chunk object, or null if the type is not supported.
     */
    private static function chunkFromType(int $type, int $flags, string $body): ?ChunkInterface
    {
        return match ($type) {
            0 => new DataChunk($flags, $body),
            1 => new InitChunk($flags, $body),
            2 => new InitAckChunk($flags, $body),
            3 => new SackChunk($flags, $body),
            4 => new HeartbeatChunk($flags, $body),
            5 => new HeartbeatAckChunk($flags, $body),
            6 => new AbortChunk($flags, $body),
            7 => new ShutdownChunk($flags, $body),
            8 => new ShutdownAckChunk($flags, $body),
            9 => new ErrorChunk($flags, $body),
            10 => new CookieEchoChunk($flags, $body),
            11 => new CookieAckChunk($flags, $body),
            14 => new ShutdownCompleteChunk($flags, $body),
            130 => new ReconfigChunk($flags, $body),
            192 => new ForwardTsnChunk($flags, $body),
            default => null,
        };
    }

    /**
     * Encodes SCTP packet components into a binary packet.
     *
     * Constructs a valid SCTP packet with header fields and a single chunk,
     * calculates and inserts the proper checksum.
     *
     * @param int $sourcePort The source port number (16-bit unsigned integer)
     * @param int $destinationPort The destination port number (16-bit unsigned integer)
     * @param int $verificationTag The verification tag (32-bit unsigned integer)
     * @param Chunk $chunk The chunk object to include in the packet
     * @return string The binary string representation of the complete SCTP packet
     */
    public static function encode(int $sourcePort, int $destinationPort, int $verificationTag, Chunk $chunk): string
    {
        $header = pack("nnN", $sourcePort, $destinationPort, $verificationTag);
        $data = $chunk->encode();
        $checksum = SctpUtility::crc32c($header . "\x00\x00\x00\x00" . $data);
        return $header . pack("V", $checksum) . $data;
    }
}
