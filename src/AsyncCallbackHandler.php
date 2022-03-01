<?php
namespace LoungeUp\Nats;

use Closure;
use Swoole\Coroutine\Channel;
use Swoole\Lock;

class AsyncCallbackHandler
{
    public Channel $mu;
    public Channel $cond;
    public ?AsyncCallback $head = null;
    public ?AsyncCallback $tail = null;

    public function __construct()
    {
        $this->mu = new Channel(1);
        $this->mu->push(1);
        $this->cond = new Channel(1);
    }

    public function asyncCBDispatcher()
    {
        while (true) {
            $this->mu->pop();

            // protect from wakeups
            // only wakeup if there is an element to pop from the list
            while ($this->head === null) {
                // this is a hack to simulate a sync.Cond of golang
                $this->mu->push(1);
                $this->cond->pop();
                $this->mu->pop();
            }

            $cur = $this->head;
            $this->head = $cur->next;
            if ($cur == $this->tail) {
                $this->tail = null;
            }

            $this->mu->push(1);

            if ($cur->f == null) {
                return;
            }

            ($cur->f)();
        }
    }

    private function pushOrClose(?Closure $f, bool $close)
    {
        $this->mu->pop();

        // prevent lib to push null closure as we use this to stop dispatcher
        if (!$close && $f == null) {
            die("null closure pushed in async dispatcher");
        }

        $cb = new AsyncCallback($f);

        if ($this->tail != null) {
            $this->tail->next = $cb;
        } else {
            $this->head = $cb;
        }

        $this->tail = $cb;

        $this->cond->push(true);
        $this->mu->push(1);
    }

    // add closure to tail and signal dispatcher
    public function push(Closure $f)
    {
        $this->pushOrClose($f, false);
    }

    // Signals that we are closing
    public function close()
    {
        $this->pushOrClose(null, true);
    }
}
