<?php

namespace LoungeUp\Nats;

enum DrainMode
{
    case DRAIN_SUBS;
    case DRAIN_PUBS;
    case DRAIN_BOTH;
}
