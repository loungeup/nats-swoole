<?php
namespace LoungeUp\Nats;

use Closure;
use Exception;

class Options
{
    public function __construct(
        public ?string $url = null,
        public array $servers = [],
        public ?bool $noRandomize = false,
        public ?bool $noEcho = false,
        public ?string $name = null,
        public ?bool $verbose = false,
        public ?bool $pedantic = false,
        public ?bool $secure = false,
        public $tlsConfig = null,
        public ?bool $allowReconnect = false,
        public ?int $maxReconnect = null,
        public ?int $reconnectWait = null,
        public ?Closure $customReconnectDelayCB = null,
        public ?int $reconnectJitter = null,
        public ?int $reconnectJitterTLS = null,
        public ?int $timeout = null,
        public ?int $drainTimeout = null,
        public ?int $flusherTimeout = null,
        public ?int $pingInterval = null,
        public ?int $maxPingsOut = null,
        public ?Closure $closedCB = null,
        public ?Closure $disconnectedCB = null,
        public ?Closure $disconnectedErrCB = null,
        public ?Closure $reconnectedCB = null,
        public ?Closure $discoveredServersCB = null,
        public ?Closure $asyncErrorCB = null,
        public ?int $reconnectBufSize = null,
        public ?int $subChanLen = null,
        public ?Closure $userJWT = null,
        public ?string $nKey = null,
        public ?Closure $signatureCB = null,
        public ?string $user = null,
        public ?string $password = null,
        public ?string $token = null,
        public ?Closure $tokenHandler = null,
        public ?bool $useOldRequestStyle = false,
        public ?bool $noCallbacksAfterClientClose = false,
        public ?Closure $lameDuckModeHandler = null,
        public ?bool $retryOnFailedConnect = false,
        public ?bool $compression = false,
        public ?string $inboxPrefix = null,
    ) {
    }

    public function connect(): Connection
    {
        $nc = new Connection(opts: $this);

        if ($nc->opts->maxPingsOut == 0) {
            $nc->opts->maxPingsOut = Defaults::MaxPingOut;
        }

        if ($nc->opts->subChanLen == 0) {
            $nc->opts->subChanLen = Defaults::MaxChanLen;
        }

        if ($nc->opts->reconnectBufSize == 0) {
            $nc->opts->reconnectBufSize = Defaults::ReconnectBufSize;
        }

        if ($nc->opts->timeout == 0) {
            $nc->opts->timeout = Defaults::Timeout;
        }

        if ($nc->opts->userJWT !== null && !empty($nc->opts->nKey)) {
            throw new Exception(Errors::ErrNkeyAndUser->value);
        }

        if (!empty($nc->opts->nKey) && $nc->opts->signatureCB === null) {
            throw new Exception(Errors::ErrNkeyButNoSigCB->value);
        }

        $nc->setupServerPool();

        $nc->ach = new AsyncCallbackHandler();

        if ($nc->opts->asyncErrorCB == null) {
            $nc->opts->asyncErrorCB = defaultErrorHandler();
        }

        $nc->newReaderWriter();

        $nc->connect();

        go([$nc->ach, "asyncCBDispatcher"]);

        return $nc;
    }
}
