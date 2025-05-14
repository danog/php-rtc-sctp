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
}