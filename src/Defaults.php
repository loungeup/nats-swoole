<?php

namespace LoungeUp\Nats;

class Defaults
{
    const Version = "0.0.0";
    const URL = "nats://127.0.0.1:4222";
    const MaxReconnect = 60;
    const ReconnectWait = 2_000_000; // microsecond
    const ReconnectJitter = 100_000; // microsecond
    const ReconnectJitterTls = 1_000_000; // microsecond
    const Timeout = 2;
    const PingInterval = 2 * 60; // second
    const MaxPingOut = 2;
    const MaxChanLen = 64 * 1024; // 64kB
    const ReconnectBufSize = 8 * 1024 * 1024; // 8MB
    const RequestChanLen = 8;
    const DrainTimeout = 30; // second
    const LangString = "php";

    const clientProtoInfo = 1;

    // Scratch storage for assembling protocol headers
    const scratchSize = 512;

    // The size of the bufio reader/writer on top of the socket.
    const defaultBufSize = 32768;

    // The buffered size of the flush "kick" channel
    const flushChanSize = 1;

    // Default server pool size
    const srvPoolSize = 4;

    // NUID size
    const nuidSize = 22;

    // Default port used if none is specified in given URL(s)
    const defaultPortString = "4222";

    const subPendingMsgsLimit = 512 * 1024;
    const subPendingBytesLimit = 64 * 1024 * 1024;
}
