<?php

namespace LoungeUp\Nats;

class ServerInfo
{
    public string $id = "";
    public string $name = "";
    public int $proto = 0;
    public string $version = "";
    public string $host = "";
    public int $port = 0;
    public bool $headers = false;
    public ?bool $authRequired = null;
    public ?bool $tlsRequired = null;
    public ?bool $tlsAvailable = null;
    public int $maxPayload = 0;
    public ?int $cid = null;
    public ?string $clientIP = null;
    public ?string $nonce = null;
    public ?string $cluster = null;
    public ?array $connectUrls = null;
    public bool $lameDuckMode = false;

    public function __construct(string $json)
    {
        $obj = json_decode($json);

        if (property_exists($obj, "server_id")) {
            $this->id = $obj->server_id;
        }

        if (property_exists($obj, "server_name")) {
            $this->name = $obj->server_name;
        }

        if (property_exists($obj, "proto")) {
            $this->proto = $obj->proto;
        }

        if (property_exists($obj, "version")) {
            $this->version = $obj->version;
        }

        if (property_exists($obj, "host")) {
            $this->host = $obj->host;
        }

        if (property_exists($obj, "port")) {
            $this->port = $obj->port;
        }

        if (property_exists($obj, "headers")) {
            $this->headers = $obj->headers;
        }

        if (property_exists($obj, "auth_required")) {
            $this->authRequired = $obj->auth_required;
        }

        if (property_exists($obj, "tls_required")) {
            $this->tlsRequired = $obj->tls_required;
        }

        if (property_exists($obj, "tls_available")) {
            $this->tlsAvailable = $obj->tls_available;
        }

        if (property_exists($obj, "max_payload")) {
            $this->maxPayload = $obj->max_payload;
        }

        if (property_exists($obj, "client_id")) {
            $this->cid = $obj->client_id;
        }

        if (property_exists($obj, "client_ip")) {
            $this->clientIP = $obj->client_ip;
        }

        if (property_exists($obj, "nonce")) {
            $this->nonce = $obj->nonce;
        }

        if (property_exists($obj, "cluster")) {
            $this->cluster = $obj->cluster;
        }

        if (property_exists($obj, "connect_urls")) {
            $this->connectUrls = $obj->connect_urls;
        }

        if (property_exists($obj, "ldm")) {
            $this->lameDuckMode = $obj->ldm;
        }
    }
}
