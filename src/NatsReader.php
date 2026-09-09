<?php

namespace LoungeUp\Nats;

use Exception;
use OpenSwoole\Coroutine\Client;
use Throwable;

class NatsReader
{
    public function __construct(
        public string $buff = "",
        public int $off = 0,
        public int $n = 0,
        public ?Client $r = null,
    ) {
    }

    public function readString(string $delim)
    {
        $s = "";

        while (true) {
            // check current buffer before reading
            if ($this->off >= 0) {
                $rest = substr($this->buff, $this->off);
                $i = strpos($rest, $delim);

                if ($i !== false) {
                    $s .= substr($rest, 0, $i + 1);
                    $this->off += $i + 1;

                    if ($this->off >= $this->n) {
                        $this->off = -1;
                    }

                    return [$s, null];
                }

                // no delim found, we need to read more
                $s .= $rest;
                $this->off = -1;
            }

            try {
                $this->read();
            } catch (Throwable $e) {
                return [$s, $e];
            }
            $this->off = 0;
        }
    }

    public function read(): ?string
    {
        if ($this->off >= 0) {
            $off = $this->off;
            $this->off = -1;
            return substr($this->buff, $off);
        }

        $data = $this->r->recv();

        if ($data == false) {
            throw new Exception("Socket Read error : {$this->r->errCode} - {$this->r->errMsg}");
        }

        if (strlen($data)) {
            $this->buff = $data;
            $this->n = strlen($data);

            return substr($this->buff, 0, $this->n);
        }
        return null;
    }
}
