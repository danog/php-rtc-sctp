<?php

namespace Tests\Webrtc\SCTP;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webrtc\SCTP\SctpUtility;

#[CoversClass(SctpUtility::class)]
class SctpUtilityTest extends TestCase
{
    public function testTsnMinusOne()
    {
        $this->assertEquals(4294967295, SctpUtility::tsnMinusOne(0));
        $this->assertEquals(0, SctpUtility::tsnMinusOne(1));
        $this->assertEquals(4294967293, SctpUtility::tsnMinusOne(4294967294));
        $this->assertEquals(4294967294, SctpUtility::tsnMinusOne(4294967295));
    }

    public function testTsnPlusOne()
    {
        $this->assertEquals(1, SctpUtility::tsnPlusOne(0));
        $this->assertEquals(2, SctpUtility::tsnPlusOne(1));
        $this->assertEquals(4294967295, SctpUtility::tsnPlusOne(4294967294));
        $this->assertEquals(0, SctpUtility::tsnPlusOne(4294967295));
    }

    public function testUint16Add()
    {
        $this->assertEquals(1, SctpUtility::uint16Add(0, 1));
        $this->assertEquals(2, SctpUtility::uint16Add(1, 1));
        $this->assertEquals(3, SctpUtility::uint16Add(1, 2));
        $this->assertEquals(65535, SctpUtility::uint16Add(65534, 1));
        $this->assertEquals(0, SctpUtility::uint16Add(65535, 1));
        $this->assertEquals(2, SctpUtility::uint16Add(65535, 3));
    }

    public function testUint16Gt()
    {
        $this->assertFalse(SctpUtility::uint16Gt(0, 1));
        $this->assertFalse(SctpUtility::uint16Gt(1, 1));
        $this->assertTrue(SctpUtility::uint16Gt(2, 1));
        $this->assertTrue(SctpUtility::uint16Gt(32768, 1));
        $this->assertFalse(SctpUtility::uint16Gt(32769, 1));
        $this->assertFalse(SctpUtility::uint16Gt(65535, 1));
    }

    public function testUint32Gt()
    {
        $this->assertFalse(SctpUtility::uint32Gt(0, 1));
        $this->assertFalse(SctpUtility::uint32Gt(1, 1));
        $this->assertTrue(SctpUtility::uint32Gt(2, 1));
        $this->assertTrue(SctpUtility::uint32Gt(2147483648, 1));
        $this->assertFalse(SctpUtility::uint32Gt(2147483649, 1));
        $this->assertFalse(SctpUtility::uint32Gt(4294967295, 1));
    }

    public function testUint32Gte()
    {
        $this->assertFalse(SctpUtility::uint32Gte(0, 1));
        $this->assertTrue(SctpUtility::uint32Gte(1, 1));
        $this->assertTrue(SctpUtility::uint32Gte(2, 1));
        $this->assertTrue(SctpUtility::uint32Gte(2147483648, 1));
        $this->assertFalse(SctpUtility::uint32Gte(2147483649, 1));
        $this->assertFalse(SctpUtility::uint32Gte(4294967295, 1));
    }

    /** The CRC-32C of RFC 3309 (SCTP), as computed byte by byte before the hash extension's was used. */
    public function testCrc32cMatchesTheReferenceImplementation(): void
    {
        $reference = static function (string $data): int {
            $crc = 0xFFFFFFFF;
            for ($i = 0, $len = \strlen($data); $i < $len; $i++) {
                $crc ^= \ord($data[$i]);
                for ($k = 0; $k < 8; $k++) {
                    $crc = $crc & 1 ? 0x82F63B78 ^ ($crc >> 1) : $crc >> 1;
                }
            }
            return $crc ^ 0xFFFFFFFF;
        };
        // The check value of CRC-32C
        self::assertSame(0xE3069283, SctpUtility::crc32c('123456789'));
        foreach ([0, 1, 3, 12, 100, 1200, 65536] as $size) {
            $data = $size ? random_bytes($size) : '';
            self::assertSame($reference($data), SctpUtility::crc32c($data), "$size bytes");
        }
    }
}
