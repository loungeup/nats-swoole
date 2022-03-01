<?php
namespace LoungeUp\Nats;

class Constants
{
    const maxTCPSize = 65535;
    const wsTextMessage = 1;
    const wsBinaryMessage = 2;
    const wsCloseMessage = 8;
    const wsPingMessage = 9;
    const wsPongMessage = 10;

    const wsFinalBit = 1 << 7;
    const wsRsv1Bit = 1 << 6;
    const wsRsv2Bit = 1 << 5;
    const wsRsv3Bit = 1 << 4;

    const wsMaskBit = 1 << 7;

    const wsContinuationFrame = 0;
    const wsMaxFrameHeaderSize = 14;
    const wsMaxControlPayloadSize = 125;
    const wsCloseSatusSize = 2;

    // From https://tools.ietf.org/html/rfc6455#section-11.7
    const wsCloseStatusNormalClosure = 1000;
    const wsCloseStatusNoStatusReceived = 1005;
    const wsCloseStatusAbnormalClosure = 1006;
    const wsCloseStatusInvalidPayloadData = 1007;

    const wsScheme = "ws";
    const wsSchemeTLS = "wss";

    const wsPMCExtension = "permessage-deflate"; // per-message compression
    const wsPMCSrvNoCtx = "server_no_context_takeover";
    const wsPMCCliNoCtx = "client_no_context_takeover";
    const wsPMCReqHeaderValue = self::wsPMCExtension . "; " . self::wsPMCSrvNoCtx . "; " . self::wsPMCCliNoCtx;

    const tlsScheme = "tls";

    const _CRLF_ = "\r\n";
    const _EMPTY_ = "";
    const _SPC_ = " ";
    const _PUB_P_ = "PUB ";
    const _HPUB_P_ = "HPUB ";

    const _OK_OP_ = "+OK";
    const _ERR_OP_ = "-ERR";
    const _PONG_OP_ = "PONG";
    const _INFO_OP_ = "INFO";

    const connectProto = "CONNECT %s" . self::_CRLF_;
    const pingProto = "PING" . self::_CRLF_;
    const pongProto = "PONG" . self::_CRLF_;
    const subProto = "SUB %s %s %d" . self::_CRLF_;
    const unsubProto = "UNSUB %d %s" . self::_CRLF_;
    const okProto = self::_OK_OP_ . self::_CRLF_;

    const hdrLine = "NATS/1.0\r\n";
    const hdrPreEnd = 8; // strlen(hdrLine) - strlen(_CRLF_)
    const statusHdr = "Status";
    const descrHdr = "Description";
    const lastConsumerSeqHdr = "Nats-Last-Consumer";
    const lastStreamSeqHdr = "Nats-Last-Stream";
    const consumerStalledHdr = "Nats-Consumer-Stalled";
    const noResponders = "503";
    const noMessagesSts = "404";
    const reqTimeoutSts = "408";
    const controlMsg = "100";
    const statusLen = 3; // e.g. 20x, 40x, 50x

    const InboxPrefix = "_INBOX.";
    const inboxPrefixLen = 7;
    const replySuffixLen = 8; // Gives us 62^8
    const rdigits = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
    const base = 62;
}
