<?php

namespace LoungeUp\Nats\Nuid;

use OpenSwoole\Coroutine;
use OpenSwoole\Lock;

abstract class Constants
{
    const digits = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
    const base = 62;
    const preLen = 12;
    const seqLen = 10;
    const maxSeq = 839299365868340224; // base^seqLen = 62^10
    const minInc = 33;
    const maxInc = 333;
    const totalLen = self::preLen + self::seqLen;
}

/**
 * NUID needs to be very fast to generate and truly unique, all while being entropy pool friendly.
 * We will use 12 bytes of crypto generated data (entropy draining), and 10 bytes of sequential data
 * that is started at a pseudo random number and increments with a pseudo-random increment.
 * Total is 22 bytes of base 62 ascii text
 */
class Nuid
{
    private $digitsArray;

    public function __construct(public string $pre, public int $seq, public int $inc)
    {
        $this->digitsArray = str_split(Constants::digits, 1);
    }

    public function randomizePrefix()
    {
        $nb = random_bytes(Constants::preLen);
        $nbArray = str_split($nb, 1);

        $this->pre = "";

        for ($i = 0; $i < Constants::preLen; $i++) {
            $this->pre .= $this->digitsArray[ord($nbArray[$i]) % Constants::base];
        }
    }

    public function next(): string
    {
        $this->seq += $this->inc;

        if ($this->seq >= Constants::maxSeq) {
            $this->randomizePrefix();
            $this->resetSequential();
        }

        $b = "";

        for ($i = Constants::totalLen, $l = $this->seq; $i > Constants::preLen; $l = intval($l / Constants::base)) {
            $i -= 1;
            $b = $this->digitsArray[$l % Constants::base] . $b;
        }
        return $this->pre . $b;
    }

    public function resetSequential()
    {
        $this->seq = rand(0, Constants::maxSeq - 1);
        $this->inc = Constants::minInc + rand(0, Constants::maxInc - Constants::minInc - 1);
    }
}

class LockedNuid
{
    public function __construct(private Nuid $nuid, public ?Lock $mutex = null)
    {
    }

    public function __call($name, $arguments)
    {
        return $this->nuid->$name(...$arguments);
    }
}

function init()
{
    $GLOBALS["globalNuid"] = new LockedNuid(newNuid(), new Lock(SWOOLE_MUTEX));
    $GLOBALS["globalNuid"]->randomizePrefix();
}

function newNuid(): Nuid
{
    $n = new Nuid(
        seq: rand(0, Constants::maxSeq - 1),
        inc: Constants::minInc + rand(0, Constants::maxInc - Constants::minInc - 1),
        pre: "",
    );

    $n->randomizePrefix();
    return $n;
}

function next(): string
{
    $hasLock = $GLOBALS["globalNuid"]->mutex->trylock();
    while (!$hasLock) {
        Coroutine::usleep(1);
        $hasLock = $GLOBALS["globalNuid"]->mutex->trylock();
    }

    $nuid = $GLOBALS["globalNuid"]->next();

    $GLOBALS["globalNuid"]->mutex->unlock();
    return $nuid;
}

if (!isset($GLOBALS["globalNuid"])) {
    init();
}
