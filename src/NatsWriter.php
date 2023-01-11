<?php

namespace LoungeUp\Nats;

use Exception;
use OpenSwoole\Coroutine\Client;

class NatsWriter
{
    public function __construct(
        public ?Client $w = null,
        public ?string $buff = "",
        public int $limit = 0,
        public ?string $pending = null,
        public int $plimit = 0,
    ) {
    }

    public function writeDirect(string ...$strs)
    {
        foreach ($strs as $str) {
            $this->w->send($str);
        }
    }

    public function appendString(string $str)
    {
        return $this->appendBufs($str);
    }

    public function appendBufs(?string ...$bufs)
    {
        foreach ($bufs as $buf) {
            if (!$buf || strlen($buf) == 0) {
                continue;
            }
            if ($this->pending !== null) {
                $this->pending .= $buf;
            } else {
                $this->buff .= $buf;
            }
        }

        /* if ($this->pending == null && strlen($this->buff) >= $this->limit) {
            return $this->flush();
        } */

        return $this->flush();
    }

    public function flush()
    {
        // If a pending buffer is set, we don't flush. Code that needs to
        // write directly to the socket, by-passing buffers during (re)connect,
        // will use the writeDirect() API.
        if ($this->pending !== null) {
            return;
        }

        if (!$this->buff || strlen($this->buff) == 0) {
            return;
        }

        // Do not skip calling w.w.Write() here if strlen(w.buff) is 0 because
        // the actual writer (if websocket for instance) may have things
        // to do such as sending control frames, etc..
        $this->w->send($this->buff);
        $this->buff = "";
    }

    public function buffered(): int
    {
        if ($this->pending !== null) {
            return strlen($this->pending);
        }
        return strlen($this->buff);
    }

    public function switchToPending()
    {
        $this->pending = "";
    }

    public function doneWithPending()
    {
        $this->pending = null;
    }

    public function flushPendingBuffer()
    {
        if ($this->pending === null || strlen($this->pending) == 0) {
            return null;
        }

        $res = $this->w->send($this->pending);
        $this->pending = "";

        if ($res === false) {
            throw new Exception("Nats Writer -> [" . $this->w->errCode . "] " . $this->w->errMsg);
        }
    }

    public function atLimitIfUsingPending(): bool
    {
        if ($this->pending === null) {
            return false;
        }

        return strlen($this->pending) >= $this->plimit;
    }
}
