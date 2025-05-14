<?php

namespace Tests\Webrtc\SCTP;

use Webrtc\SCTP\Chunk\DataChunk;
use Webrtc\SCTP\SctpConstant;

class DataChunkFactory
{
    protected int $tsn;
    protected int $streamSeq;

    public function __construct(int $tsn = 1)
    {
        $this->tsn = $tsn;
        $this->streamSeq = 0;
    }

    public function create(array $frags, bool $ordered = true): array
    {
        $chunks = [];

        foreach ($frags as $i => $frag) {
            $flags = 0;
            if (!$ordered) {
                $flags |= SctpConstant::SCTP_DATA_UNORDERED;
            }
            if ($i === 0) {
                $flags |= SctpConstant::SCTP_DATA_FIRST_FRAG;
            }
            if ($i === count($frags) - 1) {
                $flags |= SctpConstant::SCTP_DATA_LAST_FRAG;
            }

            $chunk = new DataChunk($flags);
            $chunk->setProtocol(123);
            $chunk->setStreamId(456);
            if ($ordered) {
                $chunk->setStreamSeq($this->streamSeq);
            }
            $chunk->setTsn($this->tsn);
            $chunk->setUserData($frag);
            $chunks[] = $chunk;

            $this->tsn++;
        }

        if ($ordered) {
            $this->streamSeq++;
        }

        return $chunks;
    }
}