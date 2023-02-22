<?php

namespace LoungeUp\Nats;

use OpenSwoole\Atomic\Long;

class Statistics
{
    public Long $inMsgs;
    public int $outMsgs = 0;
    public Long $inBytes;
    public int $outBytes = 0;
    public int $reconnects = 0;

    public function __construct()
    {
        $this->inMsgs = new Long(0);
        $this->inBytes = new Long(0);
    }
}
