<?php
namespace LoungeUp\Nats;

use Exception;

class Message
{
    public function __construct(
        public string $subject,
        public ?string $data = null,
        public ?Subscription $sub = null,
        public ?string $reply = null,
        public ?array $header = null,
        public ?Message $next = null,
        public ?BarrierInfo $barrier = null,
        public ?int $ackd = null,
    ) {
    }

    public function headerString(): string
    {
        $hdr = "";

        if (count($this->header) === 0) {
            return $hdr;
        }

        $hdr .= Constants::hdrLine;

        foreach ($this->header as $kk => $kv) {
            foreach ($kv as $v) {
                $hdr .= $kk . ": " . $v . "\r\n";
            }
        }

        $hdr .= Constants::_CRLF_;

        return $hdr;
    }

    public function respond(string $data)
    {
        if ($this->sub === null) {
            throw new Exception(Errors::ErrMsgNotBound->value);
        }

        if ($this->reply === null) {
            throw new Exception(Errors::ErrMsgNoReply->value);
        }

        $this->sub->mu->pop();
        $nc = $this->sub->conn;
        $this->sub->mu->push(1);

        $nc->publish($this->reply, $data);
    }
}
