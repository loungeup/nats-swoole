<?php

namespace LoungeUp\Nats;

use Closure;

use OpenSwoole\Atomic\Long;

class BarrierInfo
{
    public function __construct(public Closure $f, public ?Long $refs = new Long(0))
    {
    }
}
