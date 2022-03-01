<?php
namespace LoungeUp\Nats;

class MsgArg
{
    public string $subject;
    public ?string $reply;
    public int $sid;
    public int $hdr;
    public int $size;
}
