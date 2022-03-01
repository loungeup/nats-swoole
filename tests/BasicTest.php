<?php

use LoungeUp\Nats\Connection;
use LoungeUp\Nats\Defaults;
use Spiral\Goridge;
use Swoole\Coroutine;
use Swoole\Runtime;

beforeEach(function () {
    Runtime::enableCoroutine();
    Coroutine::set([
        "hook_flags" => SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_SOCKETS,
        "socket_timeout" => -1,
        "enable_preemptive_scheduler" => true,
    ]);
    Coroutine::enableScheduler();
});

function getStableNumCoroutine(): int
{
    $end = new DateTime();
    $end->add(new DateInterval("PT5S"));

    $base = 0;
    $old = 0;
    $same = 0;

    while (new DateTime() < $end) {
        $base = Coroutine::list()->count();
        if ($old == $base) {
            $same++;
            if ($same == 5) {
                return $base;
            }
        } else {
            $same = 0;
        }

        $old = $base;
        usleep(50 * 1000);
    }

    throw new Exception("unable to get a stable number of coroutine");
}

function checkNoCoroutineLeak(int $base, string $action)
{
    waitFor("PT2S", 100 * 1000, function () use ($base, $action) {
        $delta = Coroutine::list()->count() - $base;
        if ($delta > 0) {
            throw new Exception(sprintf("%d coroutines still exist after %s", $base, $action));
        }

        return null;
    });
}

it("should not leak coroutine on close", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));

    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");

        $base = getStableNumCoroutine();

        $nc = newDefaultConnection();
        $nc->flush();
        $nc->close();

        // sleep a little to let pong processing exit
        usleep(5 * 1000);

        $err = null;
        try {
            checkNoCoroutineLeak($base, "Close()");
        } catch (Exception $e) {
            $err = $e;
        }

        expect($err)->toBeNull();
        $nc->close();

        $rpc->call("App.Shutdown", "");
    });
});

it("should not leak coroutine on failed connect", function () {
    Co\run(function () {
        $base = getStableNumCoroutine();

        expect(fn() => Connection::createConnection(Defaults::URL))->toThrow(Exception::class);

        $err = null;
        try {
            checkNoCoroutineLeak($base, "failed connect");
        } catch (Exception $e) {
            $err = $e;
        }

        expect($err)->toBeNull();
    });
});

it("should have valid info after connect", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));

    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");

        $nc = newDefaultConnection();

        expect($nc->connectedUrl())->toBe("nats://rpcnats:4222");
        expect($nc->connectedServerId())->toBeTruthy();
        expect($nc->connectedServerName())->toBeTruthy();
        expect($nc->connectedClusterName())->toBeTruthy();

        $nc->close();

        // let some time to close gracefully
        sleep(2);

        expect($nc->connectedUrl())->toBe("");
        expect($nc->connectedServerId())->toBe("");
        expect($nc->connectedServerName())->toBe("");
        expect($nc->connectedClusterName())->toBe("");

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});
