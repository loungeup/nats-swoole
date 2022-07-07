<?php

use LoungeUp\Nats\Connection;
use LoungeUp\Nats\Constants;
use LoungeUp\Nats\Defaults;
use Swoole\Coroutine\Channel;
use Swoole\Timer;

/**
 * helper method to access private function
 */
function callMethod(string $class, string $name, object $obj, array $params)
{
    $class = new ReflectionClass($class);
    $method = $class->getMethod($name);
    $method->setAccessible(true);
    $method->invokeArgs($obj, $params);
}

/**
 * helper method to create a nats connection on port 4222
 */
function newDefaultConnection(): Connection
{
    return newConnection(intval(Defaults::defaultPortString));
}

/**
 * helper method to create a nats connection on a given port
 */
function newConnection(int $port): Connection
{
    $url = sprintf("nats://rpcnats:%d", $port);
    $nc = Connection::createConnection($url);
    return $nc;
}

function waitFor(string $totalWait, int $sleepDur, Closure $func)
{
    $end = new DateTime();
    $end->add(new DateInterval($totalWait));

    $err = null;

    while (new DateTime() < $end) {
        $err = $func();

        if ($err === null) {
            return;
        }

        usleep($sleepDur);
    }

    if ($err !== null) {
        throw $err;
    }
}

function wait(Channel $ch)
{
    return waitTime($ch, 5);
}

function waitTime(Channel $ch, int $timeout)
{
    $r = $ch->pop($timeout);
    if (!$r && $ch->errCode == -1) {
        throw new Exception("timeout waiting");
    }
    return null;
}

function checkErrChannel(Channel $ch)
{
    $out = $ch->pop(0.001);
    if ($ch->errCode == 0 && $out != null) {
        throw $out;
    }
}
