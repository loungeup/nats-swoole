<?php
namespace LoungeUp\Nats;

enum ConnectionStatus: int
{
    case DISCONNECTED = 0;
    case CONNECTED = 1;
    case CLOSED = 2;
    case RECONNECTING = 3;
    case CONNECTING = 4;
    case DRAINING_SUBS = 5;
    case DRAINING_PUBS = 6;
    case UNKNOWN = 10;
}
