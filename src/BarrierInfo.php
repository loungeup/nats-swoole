<?php

namespace LoungeUp\Nats;

use Closure;

use Swoole\Atomic\Long;

class BarrierInfo
{
    public function __construct(public Closure $f, public ?Long $refs = new Long(0))
    {
    }
}
