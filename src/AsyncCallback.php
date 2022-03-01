<?php
namespace LoungeUp\Nats;

use Closure;

class AsyncCallback
{
    public ?AsyncCallback $next = null;

    public function __construct(public ?Closure $f = null)
    {
    }
}
