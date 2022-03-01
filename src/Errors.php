<?php

namespace LoungeUp\Nats;

enum Errors: string
{
    case ErrConnectionClosed = "nats: connection closed";
    case ErrConnectionDraining = "nats: connection draining";
    case ErrDrainTimeout = "nats: draining connection timed out";
    case ErrConnectionReconnecting = "nats: connection reconnecting";
    case ErrSecureConnRequired = "nats: secure connection required";
    case ErrSecureConnWanted = "nats: secure connection not available";
    case ErrBadSubscription = "nats: invalid subscription";
    case ErrTypeSubscription = "nats: invalid subscription type";
    case ErrBadSubject = "nats: invalid subject";
    case ErrBadQueueName = "nats: invalid queue name";
    case ErrSlowConsumer = "nats: slow consumer, messages dropped";
    case ErrTimeout = "nats: timeout";
    case ErrBadTimeout = "nats: timeout invalid";
    case ErrAuthorization = "nats: authorization violation";
    case ErrAuthExpired = "nats: authentication expired";
    case ErrAuthRevoked = "nats: authentication revoked";
    case ErrAccountAuthExpired = "nats: account authentication expired";
    case ErrNoServers = "nats: no servers available for connection";
    case ErrJsonParse = "nats: connect message, json parse error";
    case ErrChanArg = "nats: argument needs to be a channel type";
    case ErrMaxPayload = "nats: maximum payload exceeded";
    case ErrMaxMessages = "nats: maximum messages delivered";
    case ErrSyncSubRequired = "nats: illegal call on an async subscription";
    case ErrMultipleTLSConfigs = "nats: multiple tls.Configs not allowed";
    case ErrNoInfoReceived = "nats: protocol exception, INFO not received";
    case ErrReconnectBufExceeded = "nats: outbound buffer limit exceeded";
    case ErrInvalidConnection = "nats: invalid connection";
    case ErrInvalidMsg = "nats: invalid message or message nil";
    case ErrInvalidArg = "nats: invalid argument";
    case ErrInvalidContext = "nats: invalid context";
    case ErrNoDeadlineContext = "nats: context requires a deadline";
    case ErrNoEchoNotSupported = "nats: no echo option not supported by this server";
    case ErrClientIDNotSupported = "nats: client ID not supported by this server";
    case ErrUserButNoSigCB = "nats: user callback defined without a signature handler";
    case ErrNkeyButNoSigCB = "nats: nkey defined without a signature handler";
    case ErrNoUserCB = "nats: user callback not defined";
    case ErrNkeyAndUser = "nats: user callback and nkey defined";
    case ErrNkeysNotSupported = "nats: nkeys not supported by the server";
    case ErrStaleConnection = "nats: stale connection";
    case ErrTokenAlreadySet = "nats: token and token handler both set";
    case ErrMsgNotBound = "nats: message is not bound to subscription/connection";
    case ErrMsgNoReply = "nats: message does not have a reply";
    case ErrClientIPNotSupported = "nats: client IP not supported by this server";
    case ErrDisconnected = "nats: server is disconnected";
    case ErrHeadersNotSupported = "nats: headers not supported by this server";
    case ErrBadHeaderMsg = "nats: message could not decode headers";
    case ErrNoResponders = "nats: no responders available for request";
    case ErrNoContextOrTimeout = "nats: no context or timeout given";
    case ErrPullModeNotAllowed = "nats: pull based not supported";
    case ErrJetStreamNotEnabled = "nats: jetstream not enabled";
    case ErrJetStreamBadPre = "nats: jetstream api prefix not valid";
    case ErrNoStreamResponse = "nats: no response from stream";
    case ErrNotJSMessage = "nats: not a jetstream message";
    case ErrInvalidStreamName = "nats: invalid stream name";
    case ErrInvalidDurableName = "nats: invalid durable name";
    case ErrNoMatchingStream = "nats: no stream matches subject";
    case ErrSubjectMismatch = "nats: subject does not match consumer";
    case ErrContextAndTimeout = "nats: context and timeout can not both be set";
    case ErrInvalidJSAck = "nats: invalid jetstream publish response";
    case ErrMultiStreamUnsupported = "nats: multiple streams are not supported";
    case ErrStreamNameRequired = "nats: stream name is required";
    case ErrStreamNotFound = "nats: stream not found";
    case ErrConsumerNotFound = "nats: consumer not found";
    case ErrConsumerNameRequired = "nats: consumer name is required";
    case ErrConsumerConfigRequired = "nats: consumer configuration is required";
    case ErrStreamSnapshotConfigRequired = "nats: stream snapshot configuration is required";
    case ErrDeliverSubjectRequired = "nats: deliver subject is required";
    case ErrPullSubscribeToPushConsumer = "nats: cannot pull subscribe to push based consumer";
    case ErrPullSubscribeRequired = "nats: must use pull subscribe to bind to pull based consumer";
    case ErrConsumerNotActive = "nats: consumer not active";
    case ErrMsgNotFound = "nats: message not found";

    // STALE_CONNECTION is for detection and proper handling of stale connections.
    case STALE_CONNECTION = "stale connection";

    // PERMISSIONS_ERR is for when nats server subject authorization has failed.
    case PERMISSIONS_ERR = "permissions violation";

    // AUTHORIZATION_ERR is for when nats server user authorization has failed.
    case AUTHORIZATION_ERR = "authorization violation";

    // AUTHENTICATION_EXPIRED_ERR is for when nats server user authorization has expired.
    case AUTHENTICATION_EXPIRED_ERR = "user authentication expired";

    // AUTHENTICATION_REVOKED_ERR is for when user authorization has been revoked.
    case AUTHENTICATION_REVOKED_ERR = "user authentication revoked";

    // ACCOUNT_AUTHENTICATION_EXPIRED_ERR is for when nats server account authorization has expired.
    case ACCOUNT_AUTHENTICATION_EXPIRED_ERR = "account authentication expired";
}
