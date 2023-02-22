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
        $s = null;
        $i = null;

        while (true) {
            // check current buffer before reading
            if ($this->off >= 0) {
                $i = strpos(substr($this->buff, $this->off, $this->n), $delim);

                if ($i && $i >= 0) {
                    $end = $this->off + $i + 1;
                    $s .= substr($this->buff, $this->off, $end);
                    $this->buff = substr($this->buff, $this->off, $end - $this->off + 1);

                    $this->off = $end;

                    if ($this->off >= $this->n) {
                        $this->off = -1;
                    }

                    return [$s, null];
                }

                // no delim found, we need to read more
                $s .= substr($this->buff, $this->off, $this->n - $this->off + 1);
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
            return substr($this->buff, $off, $this->n - $off + 1);
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
