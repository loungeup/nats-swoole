<?php

namespace LoungeUp\Nats;

use Exception;
use stdClass;

use LoungeUp\Nats\Connection;
use LoungeUp\Nats\Constants;
use LoungeUp\Nats\Errors;
use LoungeUp\Nats\Options;
use LoungeUp\Nats\Subscription;

use function LoungeUp\Nats\Nuid\next;

if (!function_exists("getDefaultOptions")) {
    function getDefaultOptions(): Options
    {
        $opts = new Options(
            allowReconnect: true,
            maxReconnect: Defaults::MaxReconnect,
            reconnectWait: Defaults::ReconnectWait,
            reconnectJitter: Defaults::ReconnectJitter,
            reconnectJitterTLS: Defaults::ReconnectJitterTls,
            timeout: Defaults::Timeout,
            pingInterval: Defaults::PingInterval,
            maxPingsOut: Defaults::MaxPingOut,
            subChanLen: Defaults::MaxChanLen,
            reconnectBufSize: Defaults::ReconnectBufSize,
            drainTimeout: Defaults::DrainTimeout,
        );

        return $opts;
    }
}

if (!function_exists("parseControl")) {
    function parseControl(string $line, stdClass &$control)
    {
        $tokens = explode(Constants::_SPC_, $line, 2);

        if (count($tokens) == 1) {
            $control->op = trim($tokens[0]);
            $control->args = Constants::_EMPTY_;
        } elseif (count($tokens) == 2) {
            $control->op = trim($tokens[0]);
            $control->args = trim($tokens[1]);
        } else {
            $control->op = Constants::_EMPTY_;
        }
    }
}

if (!function_exists("normalizeErr")) {
    function normalizeErr(string $line): string
    {
        $s = trim(ltrim($line, Constants::_ERR_OP_));
        $s = trim($s, "'");

        return $s;
    }
}

if (!function_exists("checkAuthError")) {
    function checkAuthError(string $e): ?Exception
    {
        if (str_starts_with($e, Errors::AUTHORIZATION_ERR->value)) {
            return new Exception(Errors::AUTHORIZATION_ERR->value);
        }
        if (str_starts_with($e, Errors::AUTHENTICATION_EXPIRED_ERR->value)) {
            return new Exception(Errors::AUTHENTICATION_EXPIRED_ERR->value);
        }
        if (str_starts_with($e, Errors::AUTHENTICATION_REVOKED_ERR->value)) {
            return new Exception(Errors::AUTHENTICATION_REVOKED_ERR->value);
        }
        if (str_starts_with($e, Errors::ACCOUNT_AUTHENTICATION_EXPIRED_ERR->value)) {
            return new Exception(Errors::ACCOUNT_AUTHENTICATION_EXPIRED_ERR->value);
        }

        return null;
    }
}

if (!function_exists("defaultErrorHandler")) {
    function defaultErrorHandler()
    {
        return function (Connection $nc = null, Subscription $sub = null, ?Exception $e) {
            $cid = null;
            if ($nc !== null) {
                $cid = $nc->info->cid;
            }

            $errStr = null;
            if ($sub !== null) {
                $subject = null;
                $subject = $sub->subject;

                $errStr = sprintf(
                    "\033[31m%s on connection [%d] for subscription on %s\033[0m\n",
                    $e->getMessage(),
                    $cid,
                    $subject,
                );
            } else {
                $errStr = sprintf("\033[31m%s on connection [%d]\033[0m\n", $e->getMessage(), $cid);
            }

            fwrite(STDERR, $errStr);
        };
    }
}
if (!function_exists("decodeHeadersMsg")) {
    function decodeHeadersMsg(string $data)
    {
        $t = explode("\r\n", $data);

        if ($t === false) {
            $t = explode("\n", $data);
        }

        $l = array_shift($t);
        if (
            strlen($l) < Constants::hdrPreEnd ||
            substr($l, 0, Constants::hdrPreEnd) !== substr(Constants::hdrLine, 0, Constants::hdrPreEnd)
        ) {
            throw new Exception(Errors::ErrBadHeaderMsg->value);
        }

        $mh = readMIMEHeader($t);

        // Check if we have an inlined status.
        if (strlen($l) > Constants::hdrPreEnd) {
            $description = null;
            $status = trim(substr($l, Constants::hdrPreEnd));
            if (strlen($status) !== Constants::statusLen) {
                $description = trim(substr($l, Constants::statusLen));
                $status = substr($l, 0, Constants::statusLen);
            }

            if (!isset($mh[Constants::statusHdr]) || !is_array($mh[Constants::statusHdr])) {
                $mh[Constants::statusHdr] = [];
            }

            $mh[Constants::statusHdr][] = $status;

            if ($description !== null && strlen($description) > 0) {
                if (!isset($mh[Constants::descrHdr]) || !is_array($mh[Constants::descrHdr])) {
                    $mh[Constants::descrHdr] = [];
                }

                $mh[Constants::descrHdr][] = $status;
            }
        }
        return $mh;
    }
}
if (!function_exists("readMIMEHeader")) {
    function readMIMEHeader(array $tp): array
    {
        $m = [];

        while (true) {
            $kv = array_shift($tp);
            $kvArray = str_split($kv, 1);

            if (!is_string($kv) || strlen($kv) === 0) {
                return $m;
            }

            $i = strpos($kv, ":");

            if ($i < 0) {
                throw new Exception(Errors::ErrBadHeaderMsg->value);
            }

            $key = substr($kv, 0, $i + 1);

            if ($key === "") {
                // skip empty headers
                continue;
            }

            $i++;

            while ($i < strlen($kv) && ($kvArray[$i] == " " || $kvArray[$i] == "\t")) {
                $i++;
            }

            $value = substr($kv, $i);

            if (!isset($m[$key]) || !is_array($m[$key])) {
                $m[$key] = [];
            }

            $m[$key][] = $value;
        }
    }
}

if (!function_exists("badSubject")) {
    function badSubject(string $sub): bool
    {
        if (preg_match('/\s\t\r\n/', $sub)) {
            return true;
        }

        $tokens = explode(".", $sub);
        foreach ($tokens as $t) {
            if (strlen($t) === 0) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists("badQueue")) {
    function badQueue(string $qname): bool
    {
        return preg_match('/\s\t\r\n/', $qname);
    }
}

if (!function_exists("newInbox")) {
    function newInbox(): string
    {
        $b = "";
        $b .= Constants::InboxPrefix;
        $b .= next();
        return $b;
    }
}

if (!function_exists("processUrlString")) {
    function processUrlString(string $url): array
    {
        $strs = explode(",", $url);
        return array_map("trim", $strs);
    }
}
