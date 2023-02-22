<?php

use LoungeUp\Nats\Defaults;
use LoungeUp\Nats\Message;
use LoungeUp\Nats\Options;
use Spiral\Goridge;
use Swoole\Atomic;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

use function LoungeUp\Nats\getDefaultOptions;

beforeEach(function () {
    Coroutine::set([
        "hook_flags" => SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_SOCKETS,
        "socket_timeout" => -1,
        "enable_preemptive_scheduler" => true,
    ]);

    $this->reconnectOpts = new Options(
        url: "nats://rpcnats:2222",
        allowReconnect: true,
        maxReconnect: 10,
        reconnectWait: 100_000,
        timeout: Defaults::Timeout,
    );
});

it("should not have reconnect time under 2 minutes", function () {
    $options = getDefaultOptions();
    $totalReconnectTime = $options->maxReconnect * $options->reconnectWait;

    expect($totalReconnectTime)->toBeGreaterThanOrEqual(12000000);
});

it("should correct reconnect jitter", function () {
    $options = getDefaultOptions();

    expect($options->reconnectJitter)->toBe(100000);
    expect($options->reconnectJitterTLS)->toBe(1000000);
});

it("should close properly when allowreconnect is false", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));

    co::run(function () use ($rpc) {
        $rpc->call("App.RunServerOnPort", "2222");
        $ch = new Channel(1);
        $options = getDefaultOptions();
        $options->url = "nats://rpcnats:2222";
        $options->allowReconnect = false;
        $options->closedCB = function () use ($ch) {
            $ch->push(true);
        };

        $err = null;
        try {
            $nc = $options->connect();
        } catch (Throwable $t) {
            $err = $t;
        }

        expect($err)->toBeNull();
        $rpc->call("App.Shutdown", "");

        $err = null;
        try {
            wait($ch);
        } catch (Throwable $t) {
            $err = $t;
        }

        expect($err)->toBeNull();
        $nc->close();
    });
});

it("should reconnect properly when allowreconnect is true", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));

    co::run(function () use ($rpc) {
        $rpc->call("App.RunServerOnPort", "2222");
        $ch = new Channel(1);
        $dch = new Channel(1);

        $opts = getDefaultOptions();
        $opts->url = "nats://rpcnats:2222";
        $opts->allowReconnect = true;
        $opts->maxReconnect = 2;
        $opts->reconnectWait = 1 * 1000 * 1000; // 1s
        $opts->reconnectJitter = 0;
        $opts->reconnectJitterTLS = 0;

        $opts->closedCB = fn() => $ch->push(1);
        $opts->disconnectedErrCB = fn() => $dch->push(1);

        $err = null;
        try {
            $nc = $opts->connect();
        } catch (Throwable $t) {
            $err = $t;
        }

        expect($err)->toBeNull();
        $rpc->call("App.Shutdown", "");

        expect(fn() => waitTime($ch, 0.5))->toThrow(Exception::class, "timeout waiting");

        expect(fn() => wait($dch))
            ->not()
            ->toThrow(Exception::class, "timeout waiting");

        expect($nc->isReconnecting())->toBeTrue();

        $opts->closedCB = null;
    });
});

it("should break reconnect loop on close", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));

    co::run(function () use ($rpc) {
        $rpc->call("App.RunServerOnPort", "2222");

        $cch = new Channel(1);

        $opts = $this->reconnectOpts;
        $opts->closedCB = fn() => $cch->push(1);

        $err = null;
        try {
            $nc = $opts->connect();
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        $rpc->call("App.Shutdown", "");

        sleep(1);

        go([$nc, "close"]);

        expect(fn() => waitTime($cch, 3))
            ->not()
            ->toThrow(Exception::class, "timeout waiting");
    });
});

it("should handle basic reconnect", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));

    co::run(function () use ($rpc) {
        $rpc->call("App.RunServerOnPort", "2222");

        $ch = new Channel(10);
        $dch = new Channel(2);

        $opts = $this->reconnectOpts;

        $opts->disconnectedErrCB = fn() => $dch->push(1);

        $err = null;
        try {
            $nc = $opts->connect();
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        $testString = "bar";
        $nc->subscribe("foo", function (Message $m) use ($testString, $ch) {
            if ($m->data !== $testString) {
                expect(true)->toBeFalse();
            }
            $ch->push(1);
        });

        $nc->flush();

        $rpc->call("App.Shutdown", "");

        expect(fn() => wait($dch))
            ->not()
            ->toThrow(Exception::class); // is dc callback called ?
        expect(fn() => $nc->publish("foo", $testString))
            ->not()
            ->toThrow(Exception::class);

        $rpc->call("App.RunServerOnPort", "2222");

        expect(fn() => $nc->flushTimeout(5_000_000))
            ->not()
            ->toThrow(Exception::class);
        expect(fn() => wait($ch))
            ->not()
            ->toThrow(Exception::class);

        expect($nc->stats->reconnects)->toBe(1);

        $rpc->call("App.Shutdown", "");
    });
});

it("should handle extended reconnect", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));

    co::run(function () use ($rpc) {
        $rpc->call("App.RunServerOnPort", "2222");

        /**
         * @var Options
         */
        $opts = $this->reconnectOpts;

        $dch = new Channel(2);
        $opts->disconnectedErrCB = fn() => $dch->push(1);

        $rch = new Channel(1);
        $opts->reconnectedCB = fn() => $rch->push(1);

        $err = null;
        try {
            $nc = $opts->connect();
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        $testString = "bar";
        $received = new Atomic(0);

        $nc->subscribe("foo", function (Message $m) use ($received) {
            $received->add(1);
        });

        $sub = $nc->subscribe("foobar", function (Message $m) use ($received) {
            $received->add(1);
        });

        $nc->publish("foo", $testString);
        $nc->flush();

        $rpc->call("App.Shutdown", "");

        expect(fn() => waitTime($dch, 2))
            ->not()
            ->toThrow(Exception::class);

        $nc->subscribe("bar", function (Message $m) use ($received) {
            $received->add(1);
        });

        $sub->unsubscribe();

        expect(fn() => $nc->publish("foo", $testString))
            ->not()
            ->toThrow(Exception::class);
        expect(fn() => $nc->publish("bar", $testString))
            ->not()
            ->toThrow(Exception::class);

        $rpc->call("App.RunServerOnPort", "2222");

        expect(fn() => waitTime($rch, 2))
            ->not()
            ->toThrow(Exception::class);
        expect(fn() => $nc->publish("foobar", $testString))
            ->not()
            ->toThrow(Exception::class);
        expect(fn() => $nc->publish("foo", $testString))
            ->not()
            ->toThrow(Exception::class);

        $ch = new Channel(1);
        $nc->subscribe("done", fn() => $ch->push(1));
        $nc->publish("done", null);

        expect(fn() => wait($ch))
            ->not()
            ->toThrow(Exception::class);

        usleep(50_000);

        expect($received->get())->toBe(4);

        $rpc->call("App.Shutdown", "");
    });
});
