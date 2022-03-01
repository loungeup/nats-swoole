<?php
namespace LoungeUp\Nats;

class Server
{
    public function __construct(
        public string $url,
        public bool $isImplicit,
        public string $tlsName,
        public ?bool $didConnect = null,
        public int $reconnects = 0,
        public ?string $lastError = null,
    ) {
    }
}
