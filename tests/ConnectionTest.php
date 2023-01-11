<?php

use LoungeUp\Nats\Connection;
use LoungeUp\Nats\ConnectionStatus;
use LoungeUp\Nats\Errors;
use LoungeUp\Nats\Message;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Runtime;
use Spiral\Goridge;

use function LoungeUp\Nats\getDefaultOptions;

beforeAll(function () {
    Runtime::enableCoroutine();
    Coroutine::set([
        "log_level" => SWOOLE_LOG_INFO,
        "hook_flags" => SWOOLE_HOOK_ALL,
        "socket_timeout" => -1,
        "enable_preemptive_scheduler" => true,
    ]);
    Coroutine::enableScheduler();
});

it("should handle a default connection", function () {
    expect(function () {
        $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
        co::run(function () use ($rpc) {
            $rpc->call("App.RunDefaultServer", "");
            $conn = newDefaultConnection();
            expect($conn)->toBeInstanceOf(Connection::class);
            $conn->close();
            $rpc->call("App.Shutdown", "");
        });
    })->not->toThrow(Exception::class);
});

it("should have the right connection status", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    $rpc->call("App.RunDefaultServer", "");

    co::run(function () use ($rpc) {
        $conn = newDefaultConnection();
        expect($conn)->toBeInstanceOf(Connection::class);

        expect($conn->status)->toBe(ConnectionStatus::CONNECTED);
        expect($conn->isConnected())->toBeTrue();

        $conn->close();

        expect($conn->status)->toBe(ConnectionStatus::CLOSED);
        expect($conn->isClosed())->toBeTrue();

        $rpc->call("App.Shutdown", "");
    });
});

it("should call closedCB", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));

    co::run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $ch = new Channel(1);
        $o = getDefaultOptions();
        $o->url = "nats://rpcnats:4222";

        $o->closedCB = function () use ($ch) {
            $ch->push(1);
        };

        $nc = $o->connect();

        $nc->close();

        $res = $ch->pop(5);

        expect($res)->not->toBeFalse();

        $rpc->call("App.Shutdown", "");
    });
});

it("should call disconnectedErrCB on close", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));

    co::run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $ch = new Channel(1);
        $o = getDefaultOptions();
        $o->url = "nats://rpcnats:4222";
        $o->allowReconnect = false;

        $o->disconnectedErrCB = function () use ($ch) {
            $ch->push(1);
        };

        $nc = $o->connect();

        $nc->close();

        $res = $ch->pop(5);

        expect($res)->not->toBeFalse();

        $rpc->call("App.Shutdown", "");
    });
});

it("should call disconnectedErrCB on disconnect", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));

    co::run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $ch = new Channel(1);
        $o = getDefaultOptions();
        $o->url = "nats://rpcnats:4222";
        $o->allowReconnect = false;

        $o->disconnectedErrCB = function () use ($ch) {
            $ch->push(1);
        };

        $nc = $o->connect();

        $rpc->call("App.Shutdown", "");

        $res = $ch->pop(5);
        expect($res)->not->toBeFalse();

        $nc->close();
    });
});

it("should handle a close connection", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));

    co::run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");

        $c = newDefaultConnection();

        $sub = $c->subscribeSync("foo");
        expect($sub)->not->toBeNull();

        $c->close();

        // Test all API endpoints do the right thing with a closed connection.
        expect(fn() => $c->publish("foo", null))->toThrow(Exception::class, Errors::ErrConnectionClosed->value);

        $m = new Message(subject: "foo");
        expect(fn() => $c->publishMsg($m))->toThrow(Exception::class, Errors::ErrConnectionClosed->value);
        expect(fn() => $c->flush())->toThrow(Exception::class, Errors::ErrConnectionClosed->value);
        expect(fn() => $c->subscribe("foo", null))->toThrow(Exception::class, Errors::ErrConnectionClosed->value);
        expect(fn() => $c->subscribeSync("foo"))->toThrow(Exception::class, Errors::ErrConnectionClosed->value);
        expect(fn() => $c->queueSubscribe("foo", "bar", null))->toThrow(
            Exception::class,
            Errors::ErrConnectionClosed->value,
        );
        expect(fn() => $c->request("foo", "help", 1))->toThrow(Exception::class, Errors::ErrConnectionClosed->value);
        expect(fn() => $sub->nextMsg(1))->toThrow(Exception::class, Errors::ErrConnectionClosed->value);
        expect(fn() => $sub->unsubscribe())->toThrow(Exception::class, Errors::ErrConnectionClosed->value);

        $c->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should not deadlock on connect", function () {
    Coroutine::set([
        "hook_flags" => SWOOLE_HOOK_ALL | SWOOLE_HOOK_SOCKETS,
        "socket_timeout" => -1,
        "enable_preemptive_scheduler" => true,
    ]);
    co::run(function () {
        $errCh = new Channel();

        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, "127.0.0.1", 9876);
        socket_listen($sock, 5);

        go(function () use ($sock, $errCh) {
            try {
                $msgSock = socket_accept($sock);
            } catch (Exception $e) {
                $errCh->push($e);
                return;
            }
            $errCh->push(null);
            defer(fn() => socket_close($sock));
            socket_write($msgSock, "INFOZ \r\n");
        });

        go(function () use ($errCh) {
            $url = "nats://127.0.0.1:9876";

            try {
                $nc = Connection::createConnection($url);
            } catch (Exception $e) {
                $errCh->push(null);
                return;
            }
            $nc->close();
            $errCh->push(new Exception("should have thrown exception"));
        });

        $err = $errCh->pop(1);
        expect($err)->toBeNull();
        expect($errCh->errCode)->toBe(0);

        $err = $errCh->pop(1);
        expect($err)->toBeNull();
        expect($errCh->errCode)->toBe(0);
    });
});

it("should handle connect errors", function () {})->skip("write test");
