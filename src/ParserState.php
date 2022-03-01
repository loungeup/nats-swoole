<?php
namespace LoungeUp\Nats;

enum ParserState: int
{
    case OP_START = 0;
    case OP_PLUS = 1;
    case OP_PLUS_O = 2;
    case OP_PLUS_OK = 3;
    case OP_MINUS = 4;
    case OP_MINUS_E = 5;
    case OP_MINUS_ER = 6;
    case OP_MINUS_ERR = 7;
    case OP_MINUS_ERR_SPC = 8;
    case MINUS_ERR_ARG = 9;
    case OP_M = 10;
    case OP_MS = 11;
    case OP_MSG = 12;
    case OP_MSG_SPC = 13;
    case MSG_ARG = 14;
    case MSG_PAYLOAD = 15;
    case MSG_END = 16;
    case OP_H = 17;
    case OP_P = 18;
    case OP_PI = 19;
    case OP_PIN = 20;
    case OP_PING = 21;
    case OP_PO = 22;
    case OP_PON = 23;
    case OP_PONG = 24;
    case OP_I = 25;
    case OP_IN = 26;
    case OP_INF = 27;
    case OP_INFO = 28;
    case OP_INFO_SPC = 29;
    case INFO_ARG = 30;
}
