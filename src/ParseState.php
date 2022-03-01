<?php
namespace LoungeUp\Nats;

use LoungeUp\Nats\ParserState;

class ParseState
{
    public ParserState $state = ParserState::OP_START;
    public int $as = 0;
    public int $drop = 0;
    public int $hdr = 0;
    public MsgArg $ma;
    public ?string $argBuf = null;
    public ?string $msgBuf = null;
    public bool $msgCopied;
    public string $scratch;

    public function __construct()
    {
        $this->ma = new MsgArg();
        $this->scratch = "";
    }
}
