<?php

namespace LoungeUp\Nats;

use Closure;
use Exception;
use stdClass;

use LoungeUp\Nats\Options;
use LoungeUp\Nats\ConnectionStatus;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Client;
use Swoole\Coroutine\WaitGroup;
use Swoole\Timer;
use Throwable;

use function LoungeUp\Nats\Nuid\next;

class Connection
{
    public ?Statistics $stats = null;
    public ?WaitGroup $wg = null;
    public Channel $mu;
    /**
     * @var Server[]
     */
    public array $srvPool;
    public ?Server $current;
    /**
     * @var string[]
     */
    public array $urls;
    public ?Client $conn;
    public ?Channel $fch = null;
    public ServerInfo $info;
    public int $ssid = 0;
    public ?Channel $subsMu;
    /**
     * @var Subscription[]|null
     */
    public ?array $subs = [];
    public AsyncCallbackHandler $ach;
    /**
     * @var Channel[]|null[]
     */
    public ?array $pongs = [];
    public string $scratch;
    public ConnectionStatus $status = ConnectionStatus::DISCONNECTED;
    public bool $initc = false;
    public ?Exception $err;
    public ?ParseState $ps = null;
    public ?int $ptmr = null;
    public int $pout = 0;
    public bool $ar = false;
    public ?Channel $rqch;
    public bool $ws = false;

    public string $respSub;
    public string $respSubPrefix;
    public int $respSubLen;
    public string $respScanf;
    public ?Subscription $respMux = null;
    /**
     * @var Channel[]
     */
    public ?array $respMap = null;

    public ?array $filters;

    public Channel $cond;

    public NatsReader $br;
    public NatsWriter $bw;

    public ?array $msgFilter = null;

    public int $connCpt = 0;

    public function __construct(public ?Options $opts = null)
    {
        $this->stats = new Statistics();
        $this->mu = new Channel();
        $this->mu->push(1);
        $this->subsMu = new Channel(1);
        $this->subsMu->push(1);
        $this->cond = new Channel(1);
        $this->wg = new WaitGroup();
    }

    public static function createConnection(string $url, ?Options $options = null): Connection
    {
        $opts = getDefaultOptions();
        if ($options !== null) {
            foreach (get_object_vars($options) as $var => $value) {
                if ($value !== null) {
                    $opts->$var = $value;
                }
            }
        }
        $opts->servers = processUrlString($url);

        return $opts->connect();
    }

    public function connect()
    {
        $err = null;
        $this->mu->pop();

        $this->initc = true;

        foreach ($this->srvPool as $key => $srv) {
            $this->current = $this->srvPool[$key];

            try {
                $this->createConn();
            } catch (Throwable $e) {
                $err = $e;
            }

            if (!$err) {
                $this->setup();

                try {
                    $this->processConnectInit();
                } catch (Throwable $e) {
                    echo "COULD NOT PROCESS INIT \n";
                    $err = $e;
                    echo $e->getMessage() . "\n";
                    echo $e->getFile();
                    echo $e->getLine() . "\n";
                    echo $e->getTraceAsString() . '\n';
                    $this->mu->push(1);
                    $this->closeImpl(ConnectionStatus::DISCONNECTED, false, $err);
                    $this->mu->pop();
                }

                if (!$err) {
                    $this->current->didConnect = true;
                    $this->current->reconnects = 0;
                    $this->current->lastError = null;

                    break;
                }
            } else {
                if (str_contains($err->getMessage(), "connection refused")) {
                    $err = null;
                    continue;
                }
            }
        }

        if ($err === null && $this->status !== ConnectionStatus::CONNECTED) {
            $err = new Exception(Errors::ErrNoServers->value);
        }

        if ($err === null) {
            $this->initc = false;
        } elseif ($this->opts->retryOnFailedConnect) {
            $this->setup();
            $this->status = ConnectionStatus::RECONNECTING;
            go([$this, "doReconnect"], new Exception(Errors::ErrNoServers->value));
            $err = null;
        } else {
            $this->current = null;
        }

        if ($err) {
            throw $err;
        }

        $this->mu->push(1);
    }

    private function doReconnect(Exception $e)
    {
        $this->waitForExits();

        $this->mu->pop(1);

        $this->err = null;

        // Perform appropriate callback if needed for a disconnect.
        // DisconnectedErrCB has priority over deprecated DisconnectedCB
        if (!$this->initc) {
            if ($this->opts->disconnectedErrCB !== null) {
                $this->ach->push(fn() => ($this->opts->disconnectedErrCB)($this, $e));
            } elseif ($this->opts->disconnectedCB !== null) {
                $this->ach->push(fn() => ($this->opts->disconnectedCB)($this));
            }
        }

        $waitForCoroutines = false;
        $rqch = $this->rqch;
        $wlf = null;
        $jitter = null;
        $rw = null;

        $crd = $this->opts->customReconnectDelayCB;

        if ($crd === null) {
            $rw = $this->opts->reconnectWait;
            $jitter = $this->opts->reconnectJitter;

            // TODO handle jitter tls if we add support for secure connection
        }

        for ($i = 0; count($this->srvPool) > 0; ) {
            try {
                $cur = $this->selectNextServer();
            } catch (Throwable $e) {
                $this->err = $e;
                break;
            }

            $doSleep = $i + 1 >= count($this->srvPool);

            $this->mu->push(1);

            if (!$doSleep) {
                $i++;
                usleep(5);
            } else {
                $i = 0;
                $st = null;

                if ($crd !== null) {
                    $wlf++;
                    $st = $crd($wlf);
                } else {
                    $st = $rw;

                    if ($jitter > 0) {
                        $st += rand(1, $jitter);
                    }
                }

                $rqch->pop($st / 1000 / 1000);
            }

            if ($waitForCoroutines) {
                $this->waitForExits();
                $waitForCoroutines = false;
            }

            $this->mu->pop();

            if ($this->isClosed()) {
                break;
            }

            $cur->reconnects++;

            try {
                $this->createConn();
            } catch (Throwable $e) {
                $this->err = $e;
                continue;
            }

            $this->stats->reconnects++;

            try {
                $this->processConnectInit();
            } catch (Throwable $e) {
                if ($this->ar) {
                    break;
                }

                $this->status = ConnectionStatus::RECONNECTING;
                continue;
            }

            // Clear possible lastErr under the connection lock after
            // a successful processConnectInit().
            $this->current->lastError = null;

            $cur->didConnect = true;
            $cur->reconnects = 0;

            $this->resendSubscriptions();

            try {
                $this->flushReconnectPendingItems();
            } catch (Throwable $e) {
                $this->status = ConnectionStatus::RECONNECTING;
                $this->stopPingTimer();

                // Since processConnectInit() returned without error, the
                // go routines were started, so wait for them to return
                // on the next iteration (after releasing the lock).
                $waitForCoroutines = true;
                continue;
            }

            $this->bw->doneWithPending();

            $this->status = ConnectionStatus::CONNECTED;
            $this->initc = false;

            if ($this->opts->reconnectedCB !== null) {
                $this->ach->push(fn() => ($this->opts->reconnectedCB)($this));
            }

            $this->mu->push(1);

            // make sure to flush everything
            $this->flush();

            return;
        }

        if ($this->err === null) {
            $this->err = new Exception(Errors::ErrNoServers->value);
        }

        $this->mu->push(1);
        $this->closeImpl(ConnectionStatus::CLOSED, true, null);
    }

    private function selectNextServer()
    {
        [$i, $s] = $this->currentServer();

        if ($i < 0) {
            throw new Exception(Errors::ErrNoServers->value);
        }

        $num = count($this->srvPool);
        array_splice($this->srvPool, $i, $num - 1 - $i + 1, array_slice($this->srvPool, $i + 1, $num - $i));
        $maxReconnect = $this->opts->maxReconnect;

        if ($maxReconnect < 0 || $s->reconnects < $maxReconnect) {
            $this->srvPool[$num - 1] = $s;
        } else {
            array_splice($this->srvPool, 0, $num - 1, $this->srvPool);
        }

        if (count($this->srvPool) <= 0) {
            $this->current = null;
            throw new Exception(Errors::ErrNoServers->value);
        }

        $this->current = $this->srvPool[0];
        return $this->srvPool[0];
    }

    private function waitForExits()
    {
        if (!$this->fch->isFull()) {
            $this->fch->push(true);
        }

        $this->wg->wait();
    }

    public function setupServerPool()
    {
        $this->srvPool = [];
        $this->urls = [];

        foreach ($this->opts->servers as $urlString) {
            $this->addURLToPool($urlString, false, false);
        }

        if ($this->opts->url) {
            $this->addURLToPool($this->opts->url, false, false);
            $last = count($this->srvPool) - 1;

            if ($last > 0) {
                $temp = $this->srvPool[0];
                $this->srvPool[0] = $this->srvPool[$last];
                $this->srvPool[$last] = $temp;
            }
        } elseif (count($this->srvPool) <= 0) {
            $this->addURLToPool(Defaults::URL, false, false);
        }
    }

    private function addURLToPool(string $sURL, bool $implicit, bool $saveTLSName)
    {
        if (strpos($sURL, "://") === false) {
            $sURL = sprintf("%s://%s", $this->connScheme(), $sURL);
        }

        $m = [];
        for ($i = 0; $i < 2; $i++) {
            $found = preg_match('/^(\w+:\/\/)?([\w\.]+|(?:\d{1,3}\.?){4})(?::?|:(\d+))$/', $sURL, $m);

            if (!$found) {
                throw new Exception("Invalid server url");
            }

            if (isset($m[3]) && $m[3]) {
                break;
            }

            if ($sURL[strlen($sURL) - 1] != ":") {
                $sURL .= ":";
            }

            $sURL .= Defaults::defaultPortString;
        }

        $isWS = $m[1] == Constants::wsScheme || $m[1] == Constants::wsSchemeTLS;

        if (count($this->srvPool) === 0) {
            $this->ws = $isWS;
        } elseif (($isWS && !$this->ws) || (!$isWS && $this->ws)) {
            throw new Exception("mixing of websocket and non websocket URLs is not allowed");
        }

        $tlsName = "";

        if ($implicit) {
            // TODO check if we need this, TLS + jetstream
        }

        $s = new Server(url: $sURL, isImplicit: $implicit, tlsName: $tlsName);
        $this->srvPool[] = $s;
        $this->urls[$m[2]] = new stdClass();
    }

    private function connScheme()
    {
        if ($this->ws) {
            if ($this->opts->secure) {
                return Constants::wsSchemeTLS;
            }
            return Constants::wsScheme;
        }

        if ($this->opts->secure) {
            return Constants::tlsScheme;
        }
        return "nats";
    }

    private function setup()
    {
        $this->subs = [];
        $this->pongs = [];

        $this->fch = new Channel(Defaults::flushChanSize);
        $this->rqch = new Channel(500);

        $this->scratch = Constants::_HPUB_P_;
    }

    private function createConn()
    {
        if ($this->opts->timeout < 0) {
            throw new Exception(Errors::ErrBadTimeout->value);
        }
        [, $cur] = $this->currentServer();

        if ($cur === null) {
            throw new Exception(Errors::ErrNoServers->value);
        }

        $m = [];
        preg_match('/^(\w+:\/\/)?([\w\.]+|(?:\d{1,3}\.?){4})(?::?|:(\d+))$/', $this->current->url, $m);

        $errCode = null;
        $errMsg = null;

        $this->conn = new Client(SWOOLE_SOCK_TCP);
        $this->conn->connect($m[2], $m[3], -1);

        if (!$this->conn) {
            throw new Exception("connection refused : ($errCode) $errMsg");
        }

        $this->connCpt++;

        $this->bindToNewConn();
    }

    public function newReaderWriter()
    {
        $this->br = new NatsReader(off: -1);
        $this->bw = new NatsWriter(limit: intval(Defaults::defaultBufSize), plimit: $this->opts->reconnectBufSize);
    }

    private function bindToNewConn()
    {
        $this->bw->w = $this->conn;
        $this->bw->buff = null;
        $this->br->r = $this->conn;
        $this->br->n = 0;
        $this->br->off = -1;
    }

    private function currentServer()
    {
        foreach ($this->srvPool as $k => $s) {
            if ($s == null) {
                continue;
            }

            if ($s == $this->current) {
                return [$k, $s];
            }
        }

        return [-1, null];
    }

    private function processConnectInit()
    {
        $timer = Timer::after($this->opts->timeout * 1000, function () {
            throw new Exception("[Connection::processConnectInit()] timed out");
        });

        $this->status = ConnectionStatus::CONNECTING;
        try {
            $this->processExpectedInfo();
            $this->sendConnect();
        } catch (Exception $e) {
            Timer::clear($timer);
            throw $e;
        }

        // reset pings
        $this->pout = 0;

        if ($this->opts->pingInterval > 0) {
            if ($this->ptmr === null) {
                $this->ptmr = Timer::after($this->opts->pingInterval * 1000, [$this, "processPingTimer"]);
            } else {
                Timer::clear($this->ptmr);
                $this->ptmr = Timer::after($this->opts->pingInterval * 1000, [$this, "processPingTimer"]);
            }
        }

        $this->wg->add(2);
        go([$this, "readLoop"]);
        go([$this, "flusher"]);
        Timer::clear($timer);
    }

    private function processExpectedInfo()
    {
        $c = new stdClass();
        $c->op = null;
        $c->args = null;

        $this->readOp($c);

        if ($c->op !== Constants::_INFO_OP_) {
            throw new Exception(Errors::ErrNoInfoReceived->value);
        }

        $this->processInfo($c->args);
    }

    private function sendConnect()
    {
        // construct CONNECT string
        $cProto = $this->connectProto();

        // write protocol and PING
        $this->bw->writeDirect($cProto, Constants::pingProto);

        // We don't want to read more than we need here, otherwise
        // we would need to transfer the excess read data to the readLoop.
        // Since in normal situations we just are looking for a PONG\r\n,
        // reading byte-by-byte here is ok.
        [$proto, $err] = $this->readProto();

        if ($err !== null) {
            throw $err;
        }

        // if opts.verbose is set, handle +OK
        if ($this->opts->verbose && $proto == Constants::okProto) {
            // read the rest
            [$proto, $err] = $this->readProto();

            if ($err !== null) {
                throw $err;
            }
        }

        // we expect a PONG
        if ($proto !== Constants::pongProto) {
            // but it could be something else, like -ERR

            $proto = rtrim($proto, "\r\n");

            if (str_starts_with($proto, Constants::_ERR_OP_)) {
                $proto = normalizeErr($proto);

                try {
                    checkAuthError(strtolower($proto));
                } catch (Throwable $e) {
                    // This will schedule an async error if we are in reconnect,
                    // and keep track of the auth error for the current server.
                    // If we have got the same error twice, this sets nc.ar to true to
                    // indicate that the reconnect should be aborted (will be checked
                    // in doReconnect()).
                    $this->processAuthError($e);
                }

                throw new Exception("nats: " . $proto);
            }

            throw new Exception(sprintf("nats: expected '%s', got '%s'", Constants::_PONG_OP_, $proto));
        }

        $this->status = ConnectionStatus::CONNECTED;
    }

    private function sendPing(?Channel $ch)
    {
        $this->pongs[] = $ch;
        $this->bw->appendString(Constants::pingProto);
        $this->bw->flush();
    }

    private function sendProto(string $proto)
    {
        $this->mu->pop();

        $this->bw->appendString($proto);
        $this->kickFlusher();
        $this->mu->push(1);
    }

    private function resendSubscriptions()
    {
        $this->subsMu->pop();

        /**
         * @var Subscription[]
         */
        $subs = [];
        foreach ($this->subs as &$sub) {
            $subs[] = $sub;
        }

        $this->subsMu->push(1);

        foreach ($subs as $s) {
            $adjustedMax = 0;
            $s->mu->pop();
            if ($s->max > 0) {
                if ($s->delivered < $s->max) {
                    $adjustedMax = $s->max - $s->delivered;
                }

                if ($adjustedMax === 0) {
                    $s->mu->push(1);
                    $this->bw->writeDirect(sprintf(Constants::unsubProto, $s->sid, Constants::_EMPTY_));
                    continue;
                }
            }

            $subj = $s->subject;
            $queue = $s->queue;
            $sid = $s->sid;
            $s->mu->push(1);

            $this->bw->writeDirect(sprintf(Constants::subProto, $subj, $queue, $sid));
            if ($adjustedMax > 0) {
                $this->bw->writeDirect(sprintf(Constants::unsubProto, $sid, strval($adjustedMax)));
            }
        }
    }

    private function kickFlusher()
    {
        if ($this->bw !== null && $this->fch) {
            $res = $this->fch->push(true, 0.001);
        }
    }

    private function flushReconnectPendingItems()
    {
        return $this->bw->flushPendingBuffer();
    }

    public function flushTimeout(int $microsec)
    {
        if ($microsec <= 0) {
            throw new Exception(Errors::ErrBadTimeout->value);
        }

        $this->mu->pop();

        if ($this->isClosed()) {
            $this->mu->push(1);
            throw new Exception(Errors::ErrConnectionClosed->value);
        }

        $ch = new Channel(1);
        $this->sendPing($ch);
        $this->mu->push(1);

        $ok = $ch->pop($microsec / 1000 / 1000);

        $err = null;
        if ($ok === false) {
            $err = new Exception(Errors::ErrTimeout->value);
        } elseif ($ok === null) {
            $err = new Exception(Errors::ErrConnectionClosed->value);
        } else {
            $ch->close();
        }

        if ($err !== null) {
            $this->removeFlushEntry($ch);
        }
    }

    public function flush()
    {
        return $this->flushTimeout(10 * 1000 * 1000);
    }

    private function removeFlushEntry(Channel $ch)
    {
        $this->mu->pop();

        if ($this->pongs === null) {
            return false;
        }

        foreach ($this->pongs as $k => $c) {
            if ($c == $ch) {
                $this->pongs[$k] = null;
                return true;
            }
        }
        $this->mu->push(1);
        return false;
    }

    private function connectProto(): string
    {
        $o = $this->opts;
        $nkey = null;
        $sig = null;
        $user = null;
        $pass = null;
        $token = null;
        $ujwt = null;

        if (false) {
            // TODO here we should check the user for tls connection
        } else {
            // Take from option, might be all empty strings
            $user = $o->user;
            $pass = $o->password;
            $token = $o->token;
            $nkey = $o->nKey;
        }

        if ($o->userJWT != null) {
            // TODO we don't use this, but we might add this later
        }

        if ($ujwt !== Constants::_EMPTY_ || $nkey !== Constants::_EMPTY_) {
            // TODO we don't use this, but we might add this later
        }

        if ($this->opts->tokenHandler !== null) {
            // TODO we don't use this, but we might add this later
        }

        $hdrs = $this->info->headers;
        $cinfo = new ConnectInfo(
            $o->verbose,
            $o->pedantic,
            $o->secure,
            $o->name,
            Defaults::LangString,
            Defaults::Version,
            Defaults::clientProtoInfo,
            !$o->noEcho,
            $hdrs,
            $hdrs,
            $ujwt,
            $nkey,
            $sig,
            $user,
            $pass,
            $token,
        );

        $b = $cinfo->toJson();

        // Check if NoEcho is set and we have a server that supports it
        if ($o->noEcho && $this->info->proto < 1) {
            throw new Exception(Errors::ErrNoEchoNotSupported->value);
        }

        return sprintf(Constants::connectProto, $b);
    }

    private function processPingTimer()
    {
        $this->mu->pop();

        if ($this->status !== ConnectionStatus::CONNECTED) {
            $this->mu->push(1);
            return;
        }

        //check for violation
        $this->pout++;
        if ($this->pout > $this->opts->maxPingsOut) {
            $this->mu->push(1);
            $this->processOpErr(new Exception(Errors::ErrStaleConnection->value));
            return;
        }

        $this->sendPing(null);
        $this->ptmr = Timer::after($this->opts->pingInterval * 1000, [$this, "processPingTimer"]);
        $this->mu->push(1);
    }

    private function readLoop()
    {
        // release on exit
        defer(fn() => $this->wg->done());

        // create parseState if needed
        $this->mu->pop();

        if ($this->ps === null) {
            $this->ps = new ParseState();
        }
        $conn = $this->conn;
        $br = $this->br;
        $this->mu->push(1);

        if ($conn == null) {
            return;
        }

        while (true) {
            $buff = null;
            $err = null;

            try {
                $buff = $br->read();
                if ($buff) {
                    $this->parse($buff);
                }
            } catch (Throwable $e) {
                // do not print an error if we are closing the connection
                if ($conn !== null && !$this->isClosed()) {
                    echo "[readLoop] Exception\n";
                    var_dump($e->getMessage());
                    var_dump($e->getLine());
                    var_dump($e->getTraceAsString());
                }
                $err = $e;
            }

            if ($err !== null) {
                $this->processOpErr($err);
                break;
            }
        }

        $this->mu->pop();

        $this->ps = null;
        $this->mu->push(1);
    }

    private function flusher()
    {
        defer(fn() => $this->wg->done());

        $this->mu->pop();

        $bw = $this->bw;
        $conn = $this->conn;
        $cpt = $this->connCpt;
        $fch = $this->fch;
        $this->mu->push(1);

        if ($conn == null || $bw == null) {
            return;
        }

        while (true) {
            $ok = $this->fch->pop();

            if ($ok === false) {
                return;
            }

            $this->mu->pop();

            if (!$this->isConnected() || $this->isConnecting() || $cpt != $this->connCpt) {
                $this->mu->push(1);
                return;
            }

            if ($bw->buffered() > 0) {
                try {
                    $bw->flush();
                } catch (Throwable $e) {
                    if ($this->err == null) {
                        $this->err = $e;
                    }
                }
            }
            $this->mu->push(1);
        }
    }

    private function readOp(stdClass &$c)
    {
        [$line, $err] = $this->readProto();
        if ($err !== null) {
            throw $err;
        }
        parseControl($line, $c);
    }

    private function readProto()
    {
        return $this->br->readString("\n");
    }

    private function processInfo(string $args)
    {
        if ($args == Constants::_EMPTY_) {
            return;
        }

        $this->info = new ServerInfo($args);

        // The array could be empty/not present on initial connect,
        // if advertise is disabled on that server, or servers that
        // did not include themselves in the async INFO protocol.
        // If empty, do not remove the implicit servers from the pool.
        if (is_array($this->info->connectUrls) && count($this->info->connectUrls) == 0) {
            if (!$this->initc && $this->info->lameDuckMode && $this->opts->lameDuckModeHandler !== null) {
                $this->ach->push($this->opts->lameDuckModeHandler);
            }
            return;
        }

        // TODO we should not need pool handling
        // must check anyway, nats.go line 3050
    }

    private function processAuthError(Exception $e): bool
    {
        $this->err = $e;

        if (!$this->initc && $this->opts->asyncErrorCB !== null) {
            $this->ach->push(function () use ($e) {
                ($this->opts->asyncErrorCB)($this, null, $e);
            });
        }

        if ($this->current->lastError == $e->getMessage()) {
            $this->ar = true;
        } else {
            $this->current->lastError = $e->getMessage();
        }

        return $this->ar;
    }

    private function processOpErr(Exception $e)
    {
        $this->mu->pop();

        if ($this->isConnecting() || $this->isClosed() || $this->isReconnecting()) {
            $this->mu->push(1);
            return;
        }

        if ($this->opts->allowReconnect && $this->status === ConnectionStatus::CONNECTED) {
            $this->status = ConnectionStatus::RECONNECTING;
            $this->stopPingTimer();

            if ($this->conn !== null) {
                $this->conn->close();
                $this->conn = null;
            }

            $this->bw->switchToPending();
            $this->clearPendingFlushCalls();

            go([$this, "doReconnect"], $e);
            $this->mu->push(1);
            return;
        }

        $this->status = ConnectionStatus::DISCONNECTED;
        $this->err = $e;
        $this->mu->push(1);
        $this->closeImpl(ConnectionStatus::CLOSED, true, null);
    }

    private function processMsgArgs(string $arg)
    {
        if ($this->ps->hdr >= 0) {
            return $this->processHeaderMsgArgs($arg);
        }

        $argArray = str_split($arg, 1);

        $args = [];
        $start = -1;

        foreach ($argArray as $i => $b) {
            switch ($b) {
                case " ":
                case "\t":
                case "\r":
                case "\n":
                    if ($start >= 0) {
                        array_push($args, substr($arg, $start, $i - $start));
                        $start = -1;
                    }
                    break;

                default:
                    if ($start < 0) {
                        $start = $i;
                    }
                    break;
            }
        }

        if ($start >= 0) {
            array_push($args, substr($arg, $start));
        }

        switch (count($args)) {
            case 3:
                $this->ps->ma->subject = $args[0];
                $this->ps->ma->sid = is_numeric($args[1]) ? intval($args[1]) : -1;
                $this->ps->ma->reply = null;
                $this->ps->ma->size = is_numeric($args[2]) ? intval($args[2]) : -1;
                break;
            case 4:
                $this->ps->ma->subject = $args[0];
                $this->ps->ma->sid = is_numeric($args[1]) ? intval($args[1]) : -1;
                $this->ps->ma->reply = $args[2];
                $this->ps->ma->size = is_numeric($args[3]) ? intval($args[3]) : -1;
                break;
            default:
                throw new Exception(sprintf("nats: processMsgArgs Parse Error: '%s'", $arg));
        }

        if ($this->ps->ma->sid < 0) {
            throw new Exception(sprintf("nats: processMsgArgs Bad or Missing Sid: '%s'", $arg));
        }
        if ($this->ps->ma->size < 0) {
            throw new Exception(sprintf("nats: processMsgArgs Bad or Missing Size: '%s'", $arg));
        }
    }

    private function processHeaderMsgArgs(string $arg)
    {
        $argArray = str_split($arg, 1);

        $args = [];
        $start = -1;

        foreach ($argArray as $i => $b) {
            switch ($b) {
                case " ":
                case "\t":
                case "\r":
                case "\n":
                    if ($start >= 0) {
                        array_push($args, substr($arg, $start, $i - $start));
                        $start = -1;
                    }
                    break;

                default:
                    if ($start < 0) {
                        $start = $i;
                    }
                    break;
            }
        }

        if ($start >= 0) {
            array_push($args, substr($arg, $start));
        }

        switch (count($args)) {
            case 4:
                $this->ps->ma->subject = $args[0];
                $this->ps->ma->sid = $args[1];
                $this->ps->ma->reply = null;
                $this->ps->ma->hdr = $args[2];
                $this->ps->ma->size = $args[3];
                break;
            case 5:
                $this->ps->ma->subject = $args[0];
                $this->ps->ma->sid = $args[1];
                $this->ps->ma->reply = $args[2];
                $this->ps->ma->hdr = $args[3];
                $this->ps->ma->size = $args[4];
                break;
            default:
                throw new Exception(sprintf("nats: processHeaderMsgArgs Parse Error: '%s'", $arg));
        }

        if ($this->ps->ma->sid < 0) {
            throw new Exception(sprintf("nats: processHeaderMsgArgs Bad or Missing Sid: '%s'", $arg));
        }
        if ($this->ps->ma->hdr < 0 || $this->ps->ma->hdr > $this->ps->ma->size) {
            throw new Exception(sprintf("nats: processHeaderMsgArgs Bad or Missing Header Size: '%s'", $arg));
        }
        if ($this->ps->ma->size < 0) {
            throw new Exception(sprintf("nats: processHeaderMsgArgs Bad or Missing Size: '%s'", $arg));
        }
    }

    // processMsg is called by parse and will place the msg on the
    // appropriate channel/pending queue for processing. If the channel is full,
    // or the pending queue is over the pending limits, the connection is
    // considered a slow consumer.
    private function processMsg(string $data)
    {
        $this->stats->inMsgs->add(1);
        $this->stats->inBytes->add(strlen($data));

        // Don't lock the connection to avoid server cutting us off if the
        // flusher is holding the connection lock, trying to send to the server
        // that is itself trying to send data to us.
        $this->subsMu->pop();

        $sub = isset($this->subs[$this->ps->ma->sid]) ? $this->subs[$this->ps->ma->sid] : null;
        $mf = null;

        if ($this->msgFilter !== null) {
            $mf = $this->filters[$this->ps->ma->subject];
        }

        $this->subsMu->push(1);

        if ($sub === null) {
            return;
        }

        $subj = $this->ps->ma->subject;
        $reply = $this->ps->ma->reply;

        $msgPayload = $data;

        // check if we have headers encoded
        $h = null;
        $e = null;
        $ctrlMsg = false;
        $ctrlType = null;
        $fcReply = "";

        if ($this->ps->ma->hdr > 0) {
            $hbuf = substr($msgPayload, 0, $this->ps->ma->hdr);
            $msgPayload = substr($msgPayload, $this->ps->ma->hdr);

            try {
                $h = decodeHeadersMsg($hbuf);
            } catch (Throwable $e) {
                $this->mu->pop();

                $this->err = new Exception(Errors::ErrBadHeaderMsg->value);

                if ($this->opts->asyncErrorCB !== null) {
                    $this->ach->push(fn() => ($this->opts->asyncErrorCB)($this, $sub, $this->err));
                }
                $this->mu->push(1);
            }
        }

        $m = new Message(header: $h, data: $msgPayload, subject: $subj, reply: $reply, sub: $sub);

        if ($mf !== null) {
            $m = $mf($m);
            if ($m === null) {
                return;
            }
        }

        $sub->mu->pop();

        if ($sub->closed) {
            $sub->mu->push(1);
            return;
        }

        // TODO maybe add jetstream support

        // always true since we don't handle jetstream
        if (!$ctrlMsg) {
            // Subscription internal stats (applicable only for non ChanSubscription's)
            if ($sub->type !== SubscriptionType::ChanSubscription) {
                $sub->pMsgs++;
                if ($sub->pMsgs > $sub->pMsgsMax) {
                    $sub->pMsgsMax = $sub->pMsgs;
                }
                $sub->pBytes += strlen($m->data);
                if ($sub->pBytes > $sub->pBytesMax) {
                    $sub->pBytesMax = $sub->pBytes;
                }

                // check for slow consumer
                if (
                    ($sub->pMsgsLimit > 0 && $sub->pMsgs > $sub->pMsgsLimit) ||
                    ($sub->pBytesLimit > 0 && $sub->pBytes > $sub->pBytesLimit)
                ) {
                    goto slowConsumer;
                }
            }

            // We have two modes of delivery. One is the channel, used by channel
            // subscribers and syncSubscribers, the other is a linked list for async.
            if ($sub->mch !== null) {
                $result = $sub->mch->push($m, 0.1);
                if ($result === false) {
                    goto slowConsumer;
                }
            } else {
                // push onto the async list
                if ($sub->pHead === null) {
                    $sub->pHead = $m;
                    $sub->pTail = $m;

                    if ($sub->pCond !== null) {
                        $sub->pCond->push(true);
                    }
                } else {
                    $sub->pTail->next = $m;
                    $sub->pTail = $m;
                }
            }

            // TODO maybe hande jetstream
        } elseif ($ctrlType !== null) {
            // TODO handle jsi
        }

        // clear any slowconsumer status
        $sub->sc = false;
        $sub->mu->push(1);

        if ($fcReply != Constants::_EMPTY_) {
            $this->publish($fcReply, null);
        }

        // TODO handle jsi heartbeat
        return;

        slowConsumer:
        $sub->dropped++;
        $sc = !$sub->sc;
        $sub->sc = true;

        // undo stats
        if ($sub->type !== SubscriptionType::ChanSubscription) {
            $sub->pMsgs--;
            $sub->pBytes -= strlen($m->data);
        }
        $sub->mu->push(1);

        if ($sc) {
            // Now we need connection's lock and we may end-up in the situation
            // that we were trying to avoid, except that in this case, the client
            // is already experiencing client-side slow consumer situation.
            $this->mu->pop();

            $this->err = new Exception(Errors::ErrSlowConsumer->value);

            if ($this->opts->asyncErrorCB !== null) {
                $this->ach->push(fn() => ($this->opts->asyncErrorCB)($this, $sub, $this->err));
            }
            $this->mu->push(1);
        }
    }

    private function processOK()
    {
        // do nothing
    }

    private function processErr(string $ie)
    {
        $ne = normalizeErr($ie);
        $e = strtolower($ne);
        $close = false;

        if ($e === Errors::STALE_CONNECTION) {
            $this->processOpErr(new Exception(Errors::STALE_CONNECTION->value));
        } elseif (str_starts_with($e, Errors::PERMISSIONS_ERR->value)) {
            $this->processPermissionsViolation($ne);
        } elseif (($authErr = checkAuthError($e)) !== null) {
            $this->mu->pop();
            $close = $this->processAuthError($authErr);
            $this->mu->push(1);
        } else {
            $close = true;
            $this->mu->pop();
            $this->err = new Exception("nats: " . $ne);
            $this->mu->push(1);
        }

        if ($close) {
            $this->closeImpl(ConnectionStatus::CLOSED, true, null);
        }
    }

    private function processPermissionsViolation(string $err)
    {
        $this->mu->pop();

        $e = new Exception("nats: " + $err);
        $this->err = $e;

        if ($this->opts->asyncErrorCB !== null) {
            $this->ach->push(fn() => ($this->opts->asyncErrorCB)($this, null, $e));
        }

        $this->mu->push(1);
    }

    private function processPing()
    {
        $this->sendProto(Constants::pongProto);
    }

    private function processPong()
    {
        $ch = null;

        $this->mu->pop();

        if (count($this->pongs) > 0) {
            $ch = $this->pongs[0];
            array_shift($this->pongs);
        }

        $this->pout = 0;
        $this->mu->push(1);

        if ($ch !== null) {
            $ch->push(true);
        }
    }

    private function processAsyncInfo(string $info)
    {
        $this->mu->pop();

        // ignore errors, we will simply not update the server pool
        $this->processInfo($info);

        $this->mu->push(1);
    }

    private function cloneMsgArgs()
    {
        $this->ps->argBuf = "";
        $this->ps->argBuf .= $this->ps->ma->subject;
        $this->ps->argBuf .= $this->ps->ma->reply;
    }

    private function parse(string $buf)
    {
        $i = null;
        $b = null;

        $buffer = str_split($buf, 1);

        for ($i = 0; $i < count($buffer); $i++) {
            $b = $buffer[$i];

            switch ($this->ps->state) {
                case ParserState::OP_START:
                    switch ($b) {
                        case "M":
                        case "m":
                            $this->ps->state = ParserState::OP_M;
                            $this->ps->hdr = -1;
                            $this->ps->ma->hdr = -1;
                            break;
                        case "H":
                        case "h":
                            $this->ps->state = ParserState::OP_H;
                            $this->ps->hdr = 0;
                            $this->ps->ma->hdr = 0;
                            break;
                        case "P":
                        case "p":
                            $this->ps->state = ParserState::OP_P;
                            break;
                        case "+":
                            $this->ps->state = ParserState::OP_PLUS;
                            break;
                        case "-":
                            $this->ps->state = ParserState::OP_MINUS;
                            break;
                        case "I":
                        case "i":
                            $this->ps->state = ParserState::OP_I;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_H:
                    switch ($b) {
                        case "M":
                        case "m":
                            $this->ps->state = ParserState::OP_M;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_M:
                    switch ($b) {
                        case "S":
                        case "s":
                            $this->ps->state = ParserState::OP_MS;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_MS:
                    switch ($b) {
                        case "G":
                        case "g":
                            $this->ps->state = ParserState::OP_MSG;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_MSG:
                    switch ($b) {
                        case " ":
                        case "\t":
                            $this->ps->state = ParserState::OP_MSG_SPC;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_MSG_SPC:
                    switch ($b) {
                        case " ":
                        case "\t":
                            break 2;
                        default:
                            $this->ps->state = ParserState::MSG_ARG;
                            $this->ps->as = $i;
                            break;
                    }
                    break;
                case ParserState::MSG_ARG:
                    switch ($b) {
                        case "\r":
                            $this->ps->drop = 1;
                            break;
                        case "\n":
                            $arg = "";
                            if ($this->ps->argBuf !== null) {
                                $arg = $this->ps->argBuf;
                            } else {
                                $arg = substr($buf, $this->ps->as, $i - $this->ps->drop - $this->ps->as);
                            }

                            $this->processMsgArgs($arg);

                            $this->ps->drop = 0;
                            $this->ps->as = $i + 1;
                            $this->ps->state = ParserState::MSG_PAYLOAD;

                            // jump ahead with the index. If this overruns
                            // what is left we fall out and process a split buffer.
                            $i = $this->ps->as + $this->ps->ma->size - 1;
                            break;
                        default:
                            if ($this->ps->argBuf !== null) {
                                $this->ps->argBuf .= $b;
                            }
                            break;
                    }
                    break;
                case ParserState::MSG_PAYLOAD:
                    if ($this->ps->msgBuf !== null) {
                        if (strlen($this->ps->msgBuf) >= $this->ps->ma->size) {
                            $this->processMsg($this->ps->msgBuf);
                            $this->ps->argBuf = null;
                            $this->ps->msgBuf = null;
                            $this->ps->msgCopied = false;
                            $this->ps->state = ParserState::MSG_END;
                        } else {
                            $this->ps->msgBuf .= $b;
                        }
                    } elseif ($i - $this->ps->as >= $this->ps->ma->size) {
                        $this->processMsg(substr($buf, $this->ps->as, $i - $this->ps->as));
                        $this->ps->argBuf = null;
                        $this->ps->msgBuf = null;
                        $this->ps->msgCopied = false;
                        $this->ps->state = ParserState::MSG_END;
                    }
                    break;
                case ParserState::MSG_END:
                    switch ($b) {
                        case "\n":
                            $this->ps->drop = 0;
                            $this->ps->as = $i + 1;
                            $this->ps->state = ParserState::OP_START;
                            break;
                        default:
                            break 2;
                    }
                    break;
                case ParserState::OP_PLUS:
                    switch ($b) {
                        case "O":
                        case "o":
                            $this->ps->state = ParserState::OP_PLUS_O;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_PLUS_O:
                    switch ($b) {
                        case "K":
                        case "k":
                            $this->ps->state = ParserState::OP_PLUS_OK;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_PLUS_OK:
                    switch ($b) {
                        case "\n":
                            $this->processOK();
                            $this->ps->drop = 0;
                            $this->ps->state = ParserState::OP_START;
                            break;
                    }
                    break;
                case ParserState::OP_MINUS:
                    switch ($b) {
                        case "E":
                        case "e":
                            $this->ps->state = ParserState::OP_MINUS_E;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_MINUS_E:
                    switch ($b) {
                        case "R":
                        case "r":
                            $this->ps->state = ParserState::OP_MINUS_ER;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_MINUS_ER:
                    switch ($b) {
                        case "R":
                        case "r":
                            $this->ps->state = ParserState::OP_MINUS_ERR;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_MINUS_ERR:
                    switch ($b) {
                        case " ":
                        case "\t":
                            $this->ps->state = ParserState::OP_MINUS_ERR_SPC;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_MINUS_ERR_SPC:
                    switch ($b) {
                        case " ":
                        case "\t":
                            break 2;
                        default:
                            $this->ps->state = ParserState::MINUS_ERR_ARG;
                            $this->ps->as = $i;
                            break;
                    }
                    break;
                case ParserState::MINUS_ERR_ARG:
                    switch ($b) {
                        case "\r":
                            $this->ps->drop = 1;
                            break;
                        case "\n":
                            $arg = "";
                            if ($this->ps->argBuf !== null) {
                                $arg = $this->ps->argBuf;
                                $this->ps->argBuf = null;
                            } else {
                                $arg = substr($buf, $this->ps->as, $i - $this->ps->drop);
                            }

                            $this->processErr($arg);

                            $this->ps->drop = 0;
                            $this->ps->as = $i + 1;
                            $this->ps->state = ParserState::OP_START;
                            break;
                        default:
                            if ($this->ps->argBuf !== null) {
                                $this->ps->argBuf .= $b;
                            }
                            break;
                    }
                    break;
                case ParserState::OP_P:
                    switch ($b) {
                        case "I":
                        case "i":
                            $this->ps->state = ParserState::OP_PI;
                            break;
                        case "O":
                        case "o":
                            $this->ps->state = ParserState::OP_PO;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_PO:
                    switch ($b) {
                        case "N":
                        case "n":
                            $this->ps->state = ParserState::OP_PON;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_PON:
                    switch ($b) {
                        case "G":
                        case "g":
                            $this->ps->state = ParserState::OP_PONG;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_PONG:
                    switch ($b) {
                        case "\n":
                            $this->processPong();
                            $this->ps->drop = 0;
                            $this->ps->state = ParserState::OP_START;
                            break;
                    }
                    break;
                case ParserState::OP_PI:
                    switch ($b) {
                        case "N":
                        case "n":
                            $this->ps->state = ParserState::OP_PIN;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_PIN:
                    switch ($b) {
                        case "G":
                        case "g":
                            $this->ps->state = ParserState::OP_PING;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_PING:
                    switch ($b) {
                        case "\n":
                            $this->processPing();
                            $this->ps->drop = 0;
                            $this->ps->state = ParserState::OP_START;
                            break;
                    }
                    break;
                case ParserState::OP_I:
                    switch ($b) {
                        case "N":
                        case "n":
                            $this->ps->state = ParserState::OP_IN;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_IN:
                    switch ($b) {
                        case "F":
                        case "f":
                            $this->ps->state = ParserState::OP_INF;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_INF:
                    switch ($b) {
                        case "O":
                        case "o":
                            $this->ps->state = ParserState::OP_INFO;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_INFO:
                    switch ($b) {
                        case " ":
                        case "\t":
                            $this->ps->state = ParserState::OP_INFO_SPC;
                            break;
                        default:
                            goto parseErr;
                    }
                    break;
                case ParserState::OP_INFO_SPC:
                    switch ($b) {
                        case " ":
                        case "\t":
                            break 2;
                        default:
                            $this->ps->state = ParserState::INFO_ARG;
                            $this->ps->as = $i;
                            break;
                    }
                    break;
                case ParserState::INFO_ARG:
                    switch ($b) {
                        case "\r":
                            $this->ps->drop = 1;
                            break;
                        case "\n":
                            $arg = "";
                            if ($this->ps->argBuf !== null) {
                                $arg = $this->ps->argBuf;
                                $this->ps->argBuf = null;
                            } else {
                                $arg = substr($buf, $this->ps->as, $i - $this->ps->drop);
                            }

                            $this->processAsyncInfo($arg);

                            $this->ps->drop = 0;
                            $this->ps->as = $i + 1;
                            $this->ps->state = ParserState::OP_START;
                            break;
                        default:
                            if ($this->ps->argBuf !== null) {
                                $this->ps->argBuf .= $b;
                            }
                            break;
                    }
                    break;
                default:
                    goto parseErr;
            }
        }

        // check for split buffer
        if (
            ($this->ps->state === ParserState::MSG_ARG ||
                $this->ps->state === ParserState::MINUS_ERR_ARG ||
                $this->ps->state === ParserState::INFO_ARG) &&
            $this->ps->argBuf === null
        ) {
            $this->ps->argBuf = "";
            $this->ps->argBuf .= substr($buf, $this->ps->as, $i - $this->ps->drop - $this->ps->as);
            $this->ps->scratch = $this->ps->argBuf;
        }

        // check for split msg
        if ($this->ps->state === ParserState::MSG_PAYLOAD && $this->ps->msgBuf === null) {
            // We need to clone the msgArg if it is still referencing the
            // read buffer and we are not able to process the msg.
            if ($this->ps->argBuf === null) {
                $this->cloneMsgArgs();
            }

            // If we will overflow the scratch buffer, just create a
            // new buffer to hold the split message.
            if ($this->ps->ma->size > 4096 - strlen($this->ps->argBuf)) {
                $this->ps->msgBuf = substr($buf, $this->ps->as);
                $this->ps->msgCopied = true;
            } else {
                $this->ps->msgBuf = substr($buf, $this->ps->as);
                $this->ps->scratch .= $this->ps->msgBuf;
            }
        }

        return;
        parseErr:
        throw new ParseException(sprintf("nats: Parse Error [%d]: '%s'", $this->ps->state->value, substr($buf, $i)));
    }

    private function stopPingTimer()
    {
        if ($this->ptmr !== null) {
            Timer::clear($this->ptmr);
            $this->ptmr = null;
        }
    }

    // This will clear any pending flush calls and release pending calls.
    // Lock is assumed to be held by the caller.
    private function clearPendingFlushCalls()
    {
        foreach ($this->pongs as $ch) {
            if ($ch !== null) {
                $ch->close();
            }
        }

        $this->pongs = null;
    }

    private function clearPendingRequestCalls()
    {
        if ($this->respMap === null) {
            return;
        }

        foreach ($this->respMap as $key => $ch) {
            if ($ch !== null) {
                $ch->close();
                unset($this->respMap[$key]);
            }
        }
    }

    public function close()
    {
        $this->closeImpl(ConnectionStatus::CLOSED, !$this->opts->noCallbacksAfterClientClose, null);
    }

    private function closeImpl(ConnectionStatus $status, bool $doCBs, ?Exception $e)
    {
        $this->mu->pop();

        if ($this->isClosed()) {
            $this->status = $status;
            $this->mu->push(1);
            return;
        }

        $this->status = ConnectionStatus::CLOSED;

        $this->kickFlusher();

        if ($this->rqch !== null) {
            $this->rqch->close();
            $this->rqch = null;
        }

        $this->clearPendingFlushCalls();
        $this->clearPendingRequestCalls();

        $this->stopPingTimer();
        $this->ptmr = null;

        if ($this->ar && $this->conn !== null) {
            $this->conn->close();
            $this->conn = null;
        } elseif ($this->conn !== null) {
            $this->bw->flush();
        }

        $this->subsMu->pop();

        foreach ($this->subs as $s) {
            $s->mu->pop();

            // Release callers on NextMsg for SyncSubscriptions only
            if ($s->mch !== null && $s->type == SubscriptionType::SyncSubscription) {
                $s->mch->close();
            }

            $s->mch = null;
            // Mark as invalid , for signaling to deliverMsgs
            $s->closed = true;
            // Mark connection as closed in sub
            $s->connClosed = true;

            // If we have an async sub, signal it to exit
            if ($s->type == SubscriptionType::AsyncSubscription && $s->pCond !== null) {
                $s->pCond->push(true);
            }

            $s->mu->push(1);
        }

        $this->subs = null;
        $this->subsMu->push(1);

        $this->status = $status;

        // Perform appropiate callbacks if needed for a disconnect
        if ($doCBs) {
            if ($this->conn !== null) {
                if ($this->opts->disconnectedErrCB !== null) {
                    $this->ach->push(fn() => ($this->opts->disconnectedErrCB)($this, $e));
                } elseif ($this->opts->disconnectedCB !== null) {
                    $this->ach->push(fn() => ($this->opts->disconnectedCB)($this, $e));
                }
            }

            if ($this->opts->closedCB !== null) {
                $this->ach->push(fn() => ($this->opts->closedCB)($this, $e));
            }
        }

        // If this is terminal, notify asyncCB handler to exit after dispatching
        if ($status == ConnectionStatus::CLOSED) {
            $this->ach->close();
        }

        $this->mu->push(1);

        $this->conn->close();
    }

    public function isClosed(): bool
    {
        return $this->status == ConnectionStatus::CLOSED;
    }

    public function isConnecting(): bool
    {
        return $this->status == ConnectionStatus::CONNECTING;
    }

    public function isReconnecting(): bool
    {
        return $this->status == ConnectionStatus::RECONNECTING;
    }

    public function isConnected(): bool
    {
        return $this->status == ConnectionStatus::CONNECTED || $this->isDraining();
    }

    public function isDraining(): bool
    {
        return $this->status == ConnectionStatus::DRAINING_PUBS || $this->status == ConnectionStatus::DRAINING_SUBS;
    }

    public function isDrainingPubs(): bool
    {
        return $this->status == ConnectionStatus::DRAINING_PUBS;
    }

    public function publishMsg(Message $m)
    {
        $hdr = null;

        if ($m->header && count($m->header)) {
            if (!$this->info->headers) {
                throw new Exception(Errors::ErrHeadersNotSupported->value);
            }

            $hdr = $m->headerString();
        }

        return $this->publishImpl($m->subject, $m->reply, $hdr, $m->data);
    }

    // PublishRequest will perform a Publish() expecting a response on the
    // reply subject. Use Request() for automatically waiting for a response
    // inline.
    public function publishRequest(string $subj, string $reply, ?string $data)
    {
        return $this->publishImpl($subj, $reply, null, $data);
    }

    public function publish(string $subject, ?string $data)
    {
        return $this->publishImpl($subject, Constants::_EMPTY_, null, $data);
    }

    private function publishImpl(string $subj, ?string $reply, ?string $hdr, ?string $data)
    {
        if ($subj === "") {
            throw new Exception(Errors::ErrBadSubject->value);
        }

        $this->mu->pop();

        if ($this->isClosed()) {
            $this->mu->push(1);
            throw new Exception(Errors::ErrConnectionClosed->value);
        }

        if ($this->isDrainingPubs()) {
            $this->mu->push(1);
            throw new Exception(Errors::ErrConnectionDraining->value);
        }

        // Proactively reject payloads over the threshold set by server.
        $dataSize = $data ? strlen($data) : 0;
        $hdrSize = $hdr ? strlen($hdr) : 0;

        $msgSize = $dataSize + $hdrSize;
        if (!$this->initc && $msgSize > $this->info->maxPayload) {
            $this->mu->push(1);
            throw new Exception(Errors::ErrMaxPayload->value);
        }

        // Check if we are reconnecting, and if so check if
        // we have exceeded our reconnect outbound buffer limits.
        if ($this->bw->atLimitIfUsingPending()) {
            $this->mu->push(1);
            throw new Exception(Errors::ErrReconnectBufExceeded->value);
        }

        $mh = null;
        if ($hdr !== null) {
            $mh = $this->scratch;
        } else {
            $mh = substr($this->scratch, 1);
        }

        $mh .= $subj;
        $mh .= " ";

        if ($reply !== "") {
            $mh .= $reply;
            $mh .= " ";
        }

        if ($hdr !== null) {
            $mh .= strval(strlen($hdr));
            $mh .= " ";
        }

        $mh .= strval($msgSize);
        $mh .= Constants::_CRLF_;

        try {
            $this->bw->appendBufs($mh, $hdr, $data, Constants::_CRLF_);
        } catch (Throwable $e) {
            $this->mu->push(1);
            throw $e;
        }

        $this->stats->outMsgs++;
        $this->stats->outBytes += $dataSize + $hdrSize;

        if ($this->fch->isEmpty()) {
            $this->kickFlusher();
        }

        $this->mu->push(1);
    }

    private function respHandler(Message $m)
    {
        $this->mu->pop();

        if ($this->isClosed()) {
            $this->mu->push(1);
            return;
        }

        $mch = null;

        $rt = $this->respToken($m->subject);
        if ($rt !== Constants::_EMPTY_) {
            if (array_key_exists($rt, $this->respMap)) {
                $mch = $this->respMap[$rt];
                unset($this->respMap[$rt]);
            } else {
                // the reply token doesn't exist anymore
                // it's a reply we got after the request timeout
                // we can only ignore it
                $this->mu->push(1);
                return;
            }
        } elseif (count($this->respMap) === 1) {
            foreach ($this->respMap as $k => $v) {
                $mch = $v;
                unset($this->respMap[$k]);
                break;
            }
        }

        $this->mu->push(1);

        $mch->push($m, 0.01);
    }

    private function respToken(string $respInbox): string
    {
        $token = null;
        $n = sscanf($respInbox, $this->respScanf, $token);
        if ($n != 1) {
            return "";
        }

        return $token;
    }

    public function subscribe(string $subj, ?Closure $cb): Subscription
    {
        return $this->subscribeImpl($subj, Constants::_EMPTY_, $cb, null, false, null);
    }

    public function subscribeSync(string $subj): Subscription
    {
        $mch = new Channel($this->opts->subChanLen);
        return $this->subscribeImpl($subj, Constants::_EMPTY_, null, $mch, true, null);
    }

    public function queueSubscribe(string $subj, string $queue, ?Closure $cb): Subscription
    {
        return $this->subscribeImpl($subj, $queue, $cb, null, false, null);
    }

    public function queueSubscribeSync(string $subj, string $queue): Subscription
    {
        $mch = new Channel($this->opts->subChanLen);
        return $this->subscribeImpl($subj, $queue, null, $mch, true, null);
    }

    private function subscribeImpl(
        string $subj,
        string $queue,
        ?Closure $cb,
        ?Channel $ch,
        bool $isSync,
        mixed $js,
    ): Subscription {
        $this->mu->pop();
        try {
            $s = $this->subscribeLocked($subj, $queue, $cb, $ch, $isSync, $js);
        } catch (Exception $e) {
            $this->mu->push(1);
            throw $e;
        }
        $this->mu->push(1);
        return $s;
    }

    private function subscribeLocked(
        string $subj,
        string $queue,
        ?Closure $cb,
        ?Channel $ch,
        bool $isSync,
        mixed $js,
    ): Subscription {
        if (badSubject($subj)) {
            throw new Exception(Errors::ErrBadSubject->value);
        }

        if ($queue !== Constants::_EMPTY_ && badQueue($queue)) {
            throw new Exception(Errors::ErrBadQueueName->value);
        }

        if ($this->isClosed()) {
            throw new Exception(Errors::ErrConnectionClosed->value);
        }

        if ($this->isDraining()) {
            throw new Exception(Errors::ErrConnectionDraining->value);
        }

        if ($cb === null && $ch === null) {
            throw new Exception(Errors::ErrBadSubscription->value);
        }

        $sub = new Subscription(subject: $subj, queue: $queue, mcb: $cb, conn: $this);

        if ($ch !== null) {
            $sub->pMsgsLimit = $ch->capacity;
        } else {
            $sub->pMsgsLimit = Defaults::subPendingMsgsLimit;
        }

        $sub->pBytesLimit = Defaults::subPendingBytesLimit;

        $sr = false;
        if ($cb !== null) {
            $sub->type = SubscriptionType::AsyncSubscription;
            $sub->pCond = new Channel(50);
            $sr = true;
        } elseif (!$isSync) {
            $sub->type = SubscriptionType::ChanSubscription;
            $sub->mch = $ch;
        } else {
            $sub->type = SubscriptionType::SyncSubscription;
            $sub->mch = $ch;
        }

        $this->subsMu->pop();
        $this->ssid++;
        $sub->sid = $this->ssid;
        $this->subs[$sub->sid] = $sub;
        $this->subsMu->push(1);

        if ($sr) {
            go([$this, "waitForMsgs"], $sub);
        }

        if (!$this->isReconnecting()) {
            $this->bw->appendString(sprintf(Constants::subProto, $subj, $queue, $sub->sid));
            $this->kickFlusher();
        }

        return $sub;
    }

    /**
     * waitForMsgs waits on the conditional shared with readLoop and processMsg.
     * It is used to deliver messages to asynchronous subscribers.
     */
    private function waitForMsgs(Subscription $s)
    {
        $delivered = 0;
        $max = 0;
        $closed = $s->closed;

        // Used to account for adjustments to sub.pBytes when we wrap back around.
        $msgLen = -1;

        while (true) {
            $s->mu->pop();
            // Do accounting for last msg delivered here so we only lock once
            // and drain state trips after callback has returned.
            if ($msgLen >= 0) {
                $s->pMsgs--;
                $s->pBytes -= $msgLen;
                $msgLen = -1;
            }

            if ($s->pHead === null && !$s->closed) {
                $s->mu->push(1);
                $s->pCond->pop();
                $s->mu->pop();
            }

            $m = $s->pHead;

            if ($m !== null) {
                $s->pHead = $m->next;
                if ($s->pHead === null) {
                    $s->pTail = null;
                }

                if ($m->barrier !== null) {
                    $s->mu->push(1);
                    if ($m->barrier->refs->add(-1) === 0) {
                        ($m->barrier->f)();
                    }
                    continue;
                }

                $msgLen = strlen($m->data);
            }

            $mcb = $s->mcb;
            $max = $s->max;
            $closed = $s->closed;

            if (!$s->closed) {
                $s->delivered++;
                $delivered = $s->delivered;
            }
            $s->mu->push(1);

            if ($closed) {
                break;
            }

            if ($m !== null && ($max == 0 || $delivered <= $max)) {
                $mcb($m);
            }

            if ($max > 0 && $delivered >= $max) {
                $this->mu->pop();
                $this->removeSub($s);
                $this->mu->push(1);
                break;
            }
        }

        $s->mu->pop();

        for ($m = $s->pHead; $m !== null; $m = $s->pHead) {
            if ($m->barrier !== null) {
                $s->mu->push(1);

                if ($m->barrier->refs->add(-1) === 0) {
                    ($m->barrier->f)();
                }
                $s->mu->pop();
            }
            $s->pHead = $m->next;
        }

        $s->mu->push(1);
    }

    public function removeSub(Subscription $s)
    {
        $this->subsMu->pop();
        unset($this->subs[$s->sid]);
        $this->subsMu->push(1);
        $s->mu->pop();

        if ($s->mch !== null && $s->type === SubscriptionType::SyncSubscription) {
            $s->mch->close();
        }

        $s->mch = null;

        $s->closed = true;
        if ($s->pCond !== null) {
            $s->pCond->push(true);
        }
        $s->mu->push(1);
    }

    public function addMsgFilter(string $subject, Closure $filter)
    {
        $this->subsMu->pop();

        if ($this->filters !== null) {
            $this->filters = [];
        }
        $this->filters[$subject] = $filter;
        $this->subsMu->push(1);
    }

    public function removeMsgFilter(string $subject)
    {
        $this->subsMu->pop();

        if ($this->filters !== null) {
            unset($this->filters[$subject]);

            if (count($this->filters) === 0) {
                $this->filters = null;
            }
        }
        $this->subsMu->push(1);
    }

    /**
     * Helper to setup and send new request style requests. Return the chan to receive the response.
     *
     * @return Channel[]|string[]|Exception[]
     */
    private function createNewRequestAndSend(string $subj, ?string $hdr, ?string $data): array
    {
        $this->mu->pop();

        if ($this->respMap === null) {
            $this->initNewResp();
        }

        $mch = new Channel(Defaults::RequestChanLen);
        $respInbox = $this->newRespInboxImpl();
        $token = substr($respInbox, $this->respSubLen);

        $this->respMap[$token] = $mch;

        if ($this->respMux === null) {
            // Create the response subscription we will use for all new style responses.
            // This will be on an _INBOX with an additional terminal token. The subscription
            // will be on a wildcard.
            try {
                $s = $this->subscribeLocked(
                    $this->respSub,
                    Constants::_EMPTY_,
                    Closure::fromCallable([$this, "respHandler"]),
                    null,
                    false,
                    null,
                );
            } catch (Throwable $e) {
                $this->mu->push(1);
                return [null, $token, $e];
            }

            $this->respScanf = str_replace("*", "%s", $this->respSub);
            $this->respMux = $s;
        }

        $this->mu->push(1);

        try {
            $this->publishImpl($subj, $respInbox, $hdr, $data);
        } catch (Throwable $e) {
            return [null, $token, $e];
        }

        return [$mch, $token, null];
    }

    private function initNewResp()
    {
        $this->respSubPrefix = sprintf("%s.", $this->newInbox());
        $this->respSubLen = strlen($this->respSubPrefix);
        $this->respSub = sprintf("%s*", $this->respSubPrefix);
        $this->respMap = [];
    }

    private function newInbox(): string
    {
        if ($this->opts->inboxPrefix === Constants::_EMPTY_ || $this->opts->inboxPrefix === null) {
            return newInbox();
        }

        $b = $this->opts->inboxPrefix;
        $b .= ".";
        $b .= next();

        return $b;
    }

    private function newRespInboxImpl(): string
    {
        if ($this->respMap === null) {
            $this->initNewResp();
        }

        $b = $this->respSubPrefix;
        $rn = random_int(0, PHP_INT_MAX);
        $rdigitsArr = str_split(Constants::rdigits, 1);

        for ($i = 0; $i < Constants::replySuffixLen; $i++) {
            $b .= $rdigitsArr[$rn % Constants::base];
            $rn = intval($rn / Constants::base);
        }

        return $b;
    }

    private function newRespInbox(): string
    {
        $this->mu->pop();
        $s = $this->newRespInboxImpl();
        $this->mu->push(1);
        return $s;
    }

    // RequestMsg will send a request payload including optional headers and deliver
    // the response message, or an error, including a timeout if no message was received properly
    public function requestMsg(Message $msg, float $timeout): Message
    {
        $hdr = null;

        if (count($msg->header) > 0) {
            if (!$this->info->headers) {
                throw new Exception(Errors::ErrHeadersNotSupported->value);
            }

            $hdr = $msg->headerString();
        }

        return $this->requestImpl($msg->subject, $hdr, $msg->data, $timeout);
    }

    // Request will send a request payload and deliver the response message,
    // or an error, including a timeout if no message was received properly.
    public function request(string $subj, ?string $data, float $timeout): Message
    {
        return $this->requestImpl($subj, null, $data, $timeout);
    }

    private function useOldRequestStyle(): bool
    {
        $b = $this->opts->useOldRequestStyle;
        return $b;
    }

    private function requestImpl(string $subj, ?string $hdr, ?string $data, float $timeout): Message
    {
        $m = null;
        $err = null;

        if ($this->useOldRequestStyle()) {
            try {
                $m = $this->oldRequest($subj, $hdr, $data, $timeout);
            } catch (Throwable $e) {
                $err = $e;
            }
        } else {
            try {
                $m = $this->newRequest($subj, $hdr, $data, $timeout);
            } catch (Throwable $e) {
                $err = $e;
            }
        }

        // check for no responder
        if (
            $err == null &&
            strlen($m->data) == 0 &&
            isset($m->header[Constants::statusHdr]) &&
            $m->header[Constants::statusHdr][0] == Constants::noResponders
        ) {
            $m = null;
            $err = new Exception(Errors::ErrNoResponders->value);
        }

        if ($err !== null) {
            throw $err;
        }

        return $m;
    }

    /* oldRequest will create an Inbox and perform a Request() call
     with the Inbox reply and return the first reply received.
     This is optimized for the case of multiple responses.*/
    private function oldRequest(string $subj, ?string $hdr, ?string $data, float $timeout): Message
    {
        $inbox = $this->newInbox();
        $ch = new Channel(Defaults::RequestChanLen);

        $s = null;

        $s = $this->subscribeImpl($inbox, Constants::_EMPTY_, null, $ch, true, null);

        $s->autoUnsubscribe(1);

        $this->publishImpl($subj, $inbox, $hdr, $data);

        $m = $s->nextMsg($timeout);

        // catch error when unsubscribing as we might have auto unsubscribe
        try {
            $s->unsubscribe();
        } catch (Exception $e) {
        }
        return $m;
    }

    private function newRequest(string $subj, ?string $hdr, ?string $data, float $timeout): Message
    {
        [$mch, $token, $err] = $this->createNewRequestAndSend($subj, $hdr, $data);

        if ($err !== null) {
            throw $err;
        }

        $msg = $mch->pop($timeout);

        if ($msg === false) {
            if ($mch->errCode === -2) {
                throw new Exception(Errors::ErrConnectionClosed->value);
            } elseif ($mch->errCode === -1) {
                $this->mu->pop();
                unset($this->respMap[$token]);
                $this->mu->push(1);

                throw new Exception(Errors::ErrTimeout->value);
            }
        }

        return $msg;
    }

    /**
     * unsubscribe performs the low level unsubscribe to the server.
     * Use Subscription.Unsubscribe()
     */
    public function unsubscribe(Subscription $sub, int $max, bool $drainMode)
    {
        $maxStr = "";

        if ($max > 0) {
            $sub->mu->pop();
            $sub->max = $max;

            if ($sub->delivered < $sub->max) {
                $maxStr = strval($max);
            }
            $sub->mu->push(1);
        }

        $this->mu->pop();

        if ($this->isClosed()) {
            throw new Exception(Errors::ErrConnectionClosed->value);
        }

        $this->subsMu->pop();
        $s = $this->subs[$sub->sid];
        $this->subsMu->push(1);

        if ($s === null) {
            return;
        }

        if ($maxStr === Constants::_EMPTY_ && !$drainMode) {
            $this->removeSub($s);
        }

        if ($drainMode) {
            go([$this, "checkDrained"], $sub);
        }

        if (!$this->isReconnecting()) {
            $this->bw->appendString(sprintf(Constants::unsubProto, $s->sid, $maxStr));
            $this->kickFlusher();
        }
        $this->mu->push(1);
    }

    private function checkDrained(Subscription $sub)
    {
        if ($sub === null) {
            return;
        }

        $this->flush();

        // TODO Maybe handle jetstream ?

        while (true) {
            if ($this->isClosed()) {
                return;
            }

            $sub->mu->pop();
            $conn = $sub->conn;
            $closed = $sub->closed;
            $pMsgs = $sub->pMsgs;
            $sub->mu->push(1);

            if ($conn === null || $closed || $pMsgs === 0) {
                $this->mu->pop();
                $this->removeSub($sub);
                $this->mu->push(1);
                return;
            }

            usleep(100000); // 100ms
        }
    }

    // TEST ACCESSORS
    public function connectedUrl(): string
    {
        $this->mu->pop();

        if ($this->status !== ConnectionStatus::CONNECTED) {
            $out = Constants::_EMPTY_;
        } else {
            $out = $this->current->url;
        }

        $this->mu->push(1);
        return $out;
    }

    public function connectedServerId(): string
    {
        $this->mu->pop();

        if ($this->status !== ConnectionStatus::CONNECTED) {
            $out = Constants::_EMPTY_;
        } else {
            $out = $this->info->id;
        }

        $this->mu->push(1);
        return $out;
    }

    public function connectedServerName(): string
    {
        $this->mu->pop();

        if ($this->status !== ConnectionStatus::CONNECTED) {
            $out = Constants::_EMPTY_;
        } else {
            $out = $this->info->name;
        }

        $this->mu->push(1);
        return $out;
    }

    public function connectedClusterName(): string
    {
        $this->mu->pop();

        if ($this->status !== ConnectionStatus::CONNECTED) {
            $out = Constants::_EMPTY_;
        } else {
            $out = $this->info->cluster;
        }

        $this->mu->push(1);
        return $out;
    }

    public function setErrorHandler(Closure $cb)
    {
        $this->mu->pop();
        $this->opts->asyncErrorCB = $cb;
        $this->mu->push(1);
    }

    public function buffered()
    {
        $this->mu->pop();
        if ($this->isClosed() || $this->bw == null) {
            $this->mu->push(1);
            throw new Exception(Errors::ErrConnectionClosed->value);
        }
        $buffered = $this->bw->buffered();
        $this->mu->push(1);
        return $buffered;
    }

    public function drain()
    {
        $this->mu->pop();
        if ($this->isClosed()) {
            $this->mu->push(1);
            throw new Exception(Errors::ErrConnectionClosed->value);
        }

        if ($this->isConnecting() || $this->isReconnecting()) {
            $this->mu->push(1);
            $this->close();
            throw new Exception(Errors::ErrConnectionReconnecting->value);
        }

        if ($this->isDraining()) {
            $this->mu->push(1);
            return;
        }

        $this->status = ConnectionStatus::DRAINING_SUBS;
        go([$this, "drainConnection"]);
        $this->mu->push(1);
        return;
    }

    private function drainConnection()
    {
        $this->mu->pop();

        // check again if we are in state to process
        if ($this->isClosed()) {
            $this->mu->push(1);
            return;
        }
        if ($this->isConnecting() || $this->isReconnecting()) {
            $this->mu->push(1);
            $this->close();
            return;
        }

        $subs = [];
        foreach ($this->subs as $s) {
            if ($s == $this->respMux) {
                continue;
            }
            $subs[] = $s;
        }

        $errCB = $this->opts->asyncErrorCB;
        $drainWait = $this->opts->drainTimeout;
        $respMux = $this->respMux;
        $this->mu->push(1);

        $pushErr = function (Exception $e) use ($errCB) {
            $this->mu->pop();
            $this->err = $e;
            if ($errCB != null) {
                $this->ach->push(function () use ($errCB, $e) {
                    $errCB($this, null, $e);
                });
            }

            $this->mu->push(1);
        };

        foreach ($subs as $s) {
            try {
                $s->drain();
            } catch (Exception $e) {
                $pushErr($e);
            }
        }

        $timeout = strtotime("+" . $drainWait . " sec");
        $min = null;

        if ($respMux) {
            $min = 1;
        } else {
            $min = 0;
        }

        while (strtotime("now") < $timeout) {
            if ($this->numSubscriptions() == $min) {
                break;
            }

            usleep(10000); // 10 ms
        }

        if ($respMux) {
            try {
                $respMux->drain();
            } catch (Exception $e) {
                $pushErr($e);
            }

            while (strtotime("now") < $timeout) {
                if ($this->numSubscriptions() == 0) {
                    break;
                }

                usleep(10000); // 10 ms
            }
        }

        if ($this->numSubscriptions() != 0) {
            $pushErr(new Exception(Errors::ErrDrainTimeout->value));
        }

        $this->mu->pop();
        $this->status = ConnectionStatus::DRAINING_PUBS;
        $this->mu->push(1);

        try {
            $this->flushTimeout(5 * 1000 * 1000);
        } catch (Exception $e) {
            $pushErr($e);
        }

        $this->close();
    }

    private function numSubscriptions(): int
    {
        $this->mu->pop();
        $out = count($this->subs);
        $this->mu->push(1);

        return $out;
    }
}
