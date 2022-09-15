<?php
namespace LoungeUp\Nats;

use Closure;
use Exception;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\System;
use Swoole\Lock;
use Throwable;

enum SubscriptionType: int
{
    case AsyncSubscription = 0;
    case SyncSubscription = 1;
    case ChanSubscription = 2;
    case NilSubscription = 3;
    case PullSubscription = 4;
}

class Subscription
{
    public function __construct(
        public ?Channel $mu = null,
        public ?int $sid = null,
        public ?string $subject = null,
        public ?string $queue = null,
        public int $delivered = 0,
        public int $max = 0,
        public ?Connection $conn = null,
        public ?Closure $mcb = null,
        public ?Channel $mch = null,
        public bool $closed = false,
        public bool $sc = false,
        public bool $connClosed = false,
        public SubscriptionType $type = SubscriptionType::AsyncSubscription,

        //async linked list
        public ?Message $pHead = null,
        public ?Message $pTail = null,
        public ?Channel $pCond = null,

        //stats
        public int $pMsgs = 0,
        public int $pBytes = 0,
        public int $pMsgsMax = 0,
        public int $pBytesMax = 0,
        public int $pMsgsLimit = 0,
        public int $pBytesLimit = 0,
        public int $dropped = 0,
    ) {
        $this->mu = new Channel();
        $this->mu->push(1);
    }

    public function autoUnsubscribe(int $max)
    {
        $this->mu->pop();
        $conn = $this->conn;
        $closed = $this->closed;
        $this->mu->push(1);

        if ($conn == null || $closed) {
            throw new Exception(Errors::ErrBadSubscription->value);
        }

        return $conn->unsubscribe($this, $max, false);
    }

    public function unsubscribe()
    {
        $this->mu->pop();
        $conn = $this->conn;
        $closed = $this->closed;
        $this->mu->push(1);

        if ($conn === null || $conn->isClosed()) {
            throw new Exception(Errors::ErrConnectionClosed->value);
        }

        if ($closed) {
            throw new Exception(Errors::ErrBadSubscription->value);
        }

        if ($conn->isDraining()) {
            throw new Exception(Errors::ErrConnectionDraining->value);
        }

        $conn->unsubscribe($this, 0, false);
    }

    public function nextMsg(float $timeout): Message
    {
        $this->mu->pop();

        try {
            $this->validateNextMsgState(false);
        } catch (Throwable $e) {
            $this->mu->push(1);
            throw $e;
        }

        $mch = $this->mch;
        $this->mu->push(1);

        $msg = null;

        // optimize immediate read
        $msg = $mch->pop(0.001);
        if ($mch->errCode === -2) {
            throw $this->getNextMsgErr();
        } elseif ($mch->errCode !== -1) {
            $this->processNextMsgDelivered($msg);
            return $msg;
        }

        $msg = $mch->pop($timeout);

        if ($mch->errCode === -2) {
            throw $this->getNextMsgErr();
        } elseif ($mch->errCode === -1) {
            throw new Exception(Errors::ErrTimeout->value);
        } else {
            $this->processNextMsgDelivered($msg);
            return $msg;
        }
    }

    private function getNextMsgErr()
    {
        $this->mu->pop();

        if ($this->connClosed) {
            $err = new Exception(Errors::ErrConnectionClosed->value);
        } else {
            $err = new Exception(Errors::ErrBadSubscription->value);
        }
        $this->mu->push(1);
        return $err;
    }

    private function processNextMsgDelivered(Message $msg)
    {
        $this->mu->pop();
        $nc = $this->conn;
        $max = $this->max;

        $this->delivered++;
        $delivered = $this->delivered;

        if ($this->type == SubscriptionType::SyncSubscription) {
            $this->pMsgs--;
            $this->pBytes -= strlen($msg->data);
        }
        $this->mu->push(1);

        if ($max > 0) {
            if ($delivered > $max) {
                throw new Exception(Errors::ErrMaxMessages->value);
            }

            if ($delivered === $max) {
                $nc->mu->pop();
                $nc->removeSub($this);
                $nc->mu->push(1);
            }
        }

        if (strlen($msg->data) == 0 && $msg->header[Constants::statusHdr][0] === Constants::noResponders) {
            throw new Exception(Errors::ErrNoResponders->value);
        }
    }

    private function validateNextMsgState(bool $pullSubInternal)
    {
        if ($this->connClosed) {
            throw new Exception(Errors::ErrConnectionClosed->value);
        }

        if ($this->mch === null) {
            if ($this->max > 0 && $this->delivered >= $this->max) {
                throw new Exception(Errors::ErrMaxMessages->value);
            } elseif ($this->closed) {
                throw new Exception(Errors::ErrBadSubscription->value);
            }
        }

        if ($this->mcb !== null) {
            throw new Exception(Errors::ErrSyncSubRequired->value);
        }

        if ($this->sc) {
            $this->sc = false;
            throw new Exception(Errors::ErrSlowConsumer->value);
        }

        // TODO handle jetstream
    }

    public function setPendingLimit(int $msgLimit, int $bytesLimit)
    {
        $this->mu->pop();

        if ($this->conn == null || $this->closed) {
            throw new Exception(Errors::ErrBadSubscription->value);
        }

        if ($this->type == SubscriptionType::ChanSubscription) {
            throw new Exception(Errors::ErrTypeSubscription->value);
        }

        if ($msgLimit == 0 || $bytesLimit == 0) {
            throw new Exception(Errors::ErrInvalidArg->value);
        }

        $this->pMsgsLimit = $msgLimit;
        $this->pBytesLimit = $bytesLimit;

        $this->mu->push(1);
    }

    /**
     * @return int[]
     */
    public function pending(): array
    {
        $this->mu->pop();

        if ($this->conn == null || $this->closed) {
            $this->mu->push(1);
            throw new Exception(Errors::ErrBadSubscription->value);
        }

        if ($this->type == SubscriptionType::ChanSubscription) {
            $this->mu->push(1);
            throw new Exception(Errors::ErrTypeSubscription->value);
        }

        $out = [$this->pMsgs, $this->pBytes];
        $this->mu->push(1);
        return $out;
    }

    public function drain()
    {
        $this->mu->pop();
        $conn = $this->conn;
        $this->mu->push(1);

        if ($conn == null) {
            throw new Exception(Errors::ErrBadSubscription->value);
        }

        return $conn->unsubscribe($this, 0, true);
    }
}
