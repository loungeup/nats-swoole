<?php

namespace LoungeUp\Nats;

use stdClass;

class ConnectInfo
{
    public function __construct(
        public bool $verbose,
        public bool $pedantic,
        public bool $tls,
        public ?string $name,
        public string $lang,
        public string $version,
        public int $protocol,
        public bool $echo,
        public bool $headers,
        public bool $noResponders,
        public ?string $userJWT = null,
        public ?string $nkey = null,
        public ?string $signature = null,
        public ?string $user = null,
        public ?string $pass = null,
        public ?string $token = null,
    ) {
    }

    public function toJson(): string
    {
        $response = new stdClass();
        $response->verbose = $this->verbose;
        $response->pedantic = $this->pedantic;

        if ($this->userJWT) {
            $response->jwt = $this->userJWT;
        }
        if ($this->nkey) {
            $response->nkey = $this->nkey;
        }
        if ($this->signature) {
            $response->sig = $this->signature;
        }
        if ($this->user) {
            $response->user = $this->user;
        }
        if ($this->pass) {
            $response->pass = $this->pass;
        }
        if ($this->token) {
            $response->auth_token = $this->token;
        }

        $response->tls_required = $this->tls;
        $response->name = strval($this->name);
        $response->lang = $this->lang;
        $response->version = $this->version;
        $response->protocol = $this->protocol;
        $response->echo = $this->echo;
        $response->headers = $this->headers;
        $response->no_responders = $this->noResponders;

        return json_encode($response);
    }
}
