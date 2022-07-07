<?php

use LoungeUp\Nats\Connection;
use LoungeUp\Nats\Defaults;
use LoungeUp\Nats\Errors;
use LoungeUp\Nats\Message;
use LoungeUp\Nats\Options;
use Spiral\Goridge;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Swoole\Runtime;

use function LoungeUp\Nats\getDefaultOptions;
use function LoungeUp\Nats\newInbox;

beforeEach(function () {
    Coroutine::set([
        "hook_flags" => SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_SOCKETS,
        "socket_timeout" => -1,
        "enable_preemptive_scheduler" => true,
    ]);
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

it("should handle multiple close", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));

    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");

        $nc = newDefaultConnection();

        $wg = new WaitGroup();
        for ($i = 0; $i < 10; $i++) {
            $wg->add(1);
            go(function () use ($nc, $wg) {
                $nc->close();
                $wg->done();
            });
        }

        expect($wg->wait(1))->toBeTrue();

        $rpc->call("App.Shutdown", "");
    });
});

it("should throw exception on bad option timeout connect", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));

    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");

        $opts = getDefaultOptions();
        $opts->timeout = -1;
        $opts->url = "nats://rpcnats:4222";

        expect(fn() => $opts->connect())->toThrow(Exception::class, Errors::ErrBadTimeout->value);

        $rpc->call("App.Shutdown", "");
    });
});

it("should handle simple publish", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));

    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");

        $nc = newDefaultConnection();

        $e = null;

        try {
            $nc->publish("foo", "Hello World");
        } catch (Throwable $t) {
            $e = $t;
        }

        expect($e)->toBeNull();

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should handle simple publish with no data", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));

    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");

        $nc = newDefaultConnection();

        $e = null;

        try {
            $nc->publish("foo", null);
        } catch (Throwable $t) {
            $e = $t;
        }

        expect($e)->toBeNull();

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should not fail on publish on slow consumer", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");

        $nc = newDefaultConnection();

        $nc->setErrorHandler(function () {});

        $err = null;

        $sub = null;
        try {
            $sub = $nc->subscribeSync("foo");
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        try {
            $sub->setPendingLimit(1, 1000);
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        for ($i = 0; $i < 10; $i++) {
            try {
                $nc->publish("foo", "Hello");
            } catch (Throwable $t) {
                $err = $t;
                break;
            }
            $nc->flush();
        }

        expect($err)->toBeNull();

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should handle async subscribe", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $nc = newDefaultConnection();

        $omsg = "Hello World";
        $ch = new Channel();

        expect(fn() => $nc->subscribe("foo", null))->toThrow(Exception::class, "nats: invalid subscription");

        $err = null;
        try {
            $nc->subscribe("foo", function (Message $msg) use ($omsg, $ch) {
                expect($msg->data)->toEqual($omsg);
                expect($msg->sub)->not->toBeNull();
                $ch->push(true);
            });
        } catch (Throwable $t) {
            $err = $t;
        }

        expect($err)->toBeNull();

        $nc->publish("foo", $omsg);

        try {
            wait($ch);
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should not leak async subscribe coroutine on close", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");

        $ch = new Channel();
        $base = getStableNumCoroutine();

        $nc = newDefaultConnection();
        $err = null;

        try {
            $nc->subscribe("foo", function (Message $msg) use ($ch) {
                $ch->push(true);
            });
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        $nc->publish("foo", "hello");

        try {
            wait($ch);
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        // make sure that the async delivery routine go back to wait
        sleep(1);
        $nc->close();

        checkNoCoroutineLeak($base, "Close()");

        $rpc->call("App.Shutdown", "");
    });
});

it("should handle sync subscribe", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $nc = newDefaultConnection();

        $err = null;
        try {
            $sub = $nc->subscribeSync("foo");
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        $omsg = "Hello World";
        $nc->publish("foo", $omsg);

        try {
            $msg = $sub->nextMsg(1);
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();
        expect($omsg)->toEqual($msg->data);

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should handle pub sub with reply", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $nc = newDefaultConnection();

        $err = null;
        try {
            $sub = $nc->subscribeSync("foo");
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        $omsg = "Hello World";
        $nc->publishMsg(new Message(subject: "foo", reply: "bar", data: $omsg));

        try {
            $msg = $sub->nextMsg(10);
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();
        expect($omsg)->toEqual($msg->data);

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should handle msg response", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $nc = newDefaultConnection();

        $m = new Message();

        expect(fn() => $m->respond(null))->toThrow(Exception::class, Errors::ErrMsgNotBound->value);

        $err = null;

        try {
            $sub = $nc->subscribe("req", function (Message $msg) {
                $msg->respond("42");
            });
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        $m->sub = $sub;

        expect(fn() => $m->respond(null))->toThrow(Exception::class, Errors::ErrMsgNoReply->value);

        try {
            $response = $nc->request("req", "help", 0.05);
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();
        expect($response->data)->toEqual("42");

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should handle flush", function () {
    Coroutine::set([
        "max_coroutine" => "10200",
    ]);
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $nc = newDefaultConnection();

        $omsg = "Hello World";

        for ($i = 0; $i < 10000; $i++) {
            $nc->publish("flush", $omsg);
        }
        expect(fn() => $nc->flushTimeout(0))->toThrow(Exception::class, Errors::ErrBadTimeout->value);

        $err = null;
        try {
            $nc->flush();
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();
        expect($nc->buffered())->toBeLessThanOrEqual(0);

        $nc->close();

        expect(fn() => $nc->buffered())->toThrow(Exception::class, Errors::ErrConnectionClosed->value);
        $rpc->call("App.Shutdown", "");
    });
});

it("should handle queue subscribe", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $nc = newDefaultConnection();

        $s1 = $nc->queueSubscribeSync("foo", "bar");
        $s2 = $nc->queueSubscribeSync("foo", "bar");

        $omsg = "Hello World";
        $nc->publish("foo", $omsg);
        $nc->flush();

        [$r1, ,] = $s1->pending();
        [$r2, ,] = $s2->pending();

        expect($r1 + $r2)->toEqual(1);

        // drain messages
        // we expect an error here, one of the subscription will have no message
        try {
            $s1->nextMsg(1);
        } catch (Throwable $t) {
        }
        try {
            $s2->nextMsg(1);
        } catch (Throwable $t) {
        }

        $total = 1000;

        for ($i = 0; $i < $total; $i++) {
            $nc->publish("foo", $omsg);
        }
        $nc->flush();

        $v = 0.15 * $total;
        [$r1, ,] = $s1->pending();
        [$r2, ,] = $s2->pending();
        expect($r1 + $r2)->toEqual($total);

        $expected = $total / 2;
        $d1 = abs($expected - $r1);
        $d2 = abs($expected - $r2);

        expect($v)->toBeGreaterThanOrEqual($d1);
        expect($v)->toBeGreaterThanOrEqual($d2);

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should handle reply args", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $nc = newDefaultConnection();

        $ch = new Channel();
        $replyExpected = "bar";

        $nc->subscribe("foo", function (Message $msg) use ($ch, $replyExpected) {
            expect($msg->reply)->toBe($replyExpected);
            $ch->push(1);
        });

        $nc->publishMsg(new Message(subject: "foo", reply: $replyExpected, data: "Hello"));

        $err = null;
        try {
            wait($ch);
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should handle sync reply arg", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $nc = newDefaultConnection();

        $replyExpected = "bar";
        $sub = $nc->subscribeSync("foo");

        $nc->publishMsg(new Message(subject: "foo", reply: $replyExpected, data: "Hello"));

        $err = null;
        try {
            $msg = $sub->nextMsg(1);
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();
        expect($msg->reply)->toBe($replyExpected);

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should handle unsubscribe", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $nc = newDefaultConnection();

        $received = 0;
        $max = 10;
        $ch = new Channel();

        $nc->subscribe("foo", function (Message $msg) use (&$received, $max, $ch) {
            $received++;
            if ($received == $max) {
                $err = null;
                try {
                    $msg->sub->unsubscribe();
                } catch (Throwable $t) {
                    $err = $t;
                }
                expect($err)->toBeNull();
                $ch->push(true);
            }
        });

        $send = 20;

        for ($i = 0; $i < $send; $i++) {
            $nc->publish("foo", "hello");
        }

        $nc->flush();
        $ch->pop();

        expect($received)->toBe($max);

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should handle double unsubscribe", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $nc = newDefaultConnection();

        $err = null;
        try {
            $sub = $nc->subscribeSync("foo");
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        expect(fn() => $sub->unsubscribe())->not->toThrow(Exception::class);
        expect(fn() => $sub->unsubscribe())->toThrow(Exception::class, Errors::ErrBadSubscription->value);

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should throw timeout error", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $nc = newDefaultConnection();

        $nc->subscribeSync("foo");

        expect(fn() => $nc->request("foo", "help", 0.01))->toThrow(Exception::class, Errors::ErrTimeout->value);

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should support basic no responder errors", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $url = $rpc->call("App.RunServerOnPort", "-1");

        $err = null;
        try {
            $nc = Connection::createConnection(strval($url));
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        expect(fn() => $nc->request("foo", null, 1))->toThrow(Exception::class, Errors::ErrNoResponders->value);
        $nc->close();
        // test old request
        $err = null;
        try {
            $nc = Connection::createConnection(strval($url), new Options(useOldRequestStyle: true));
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        expect(fn() => $nc->request("foo", null, 1))->toThrow(Exception::class, Errors::ErrNoResponders->value);

        // subscribe sync
        $inbox = newInbox();
        $err = null;
        try {
            $sub = $nc->subscribeSync($inbox);
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        try {
            $nc->publishRequest("foo", $inbox, null);
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        expect(fn() => $sub->nextMsg(2))->toThrow(Exception::class, Errors::ErrNoResponders->value);

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should handle oldRequestStyle", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $err = null;

        try {
            $nc = Connection::createConnection("nats://rpcnats:4222", new Options(useOldRequestStyle: true));
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();

        $response = "I will help you";
        $nc->subscribe("foo", function (Message $msg) use ($response) {
            $msg->respond($response);
        });

        $err = null;
        try {
            $msg = $nc->request("foo", "hello", 0.5);
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();
        expect($msg->data)->toBe($response);

        $errCh = new Channel(1);
        $start = microtime(true);

        go(function () use ($nc, $errCh) {
            $sub = $nc->subscribeSync("checkClose");
            $err = null;
            try {
                $nc->request("checkClose", "should be kicked out on close", 1);
            } catch (Exception $e) {
                $err = $e;
            }
            $errCh->push($err);

            try {
                $sub->unsubscribe();
            } catch (Exception $e) {
            }
        });

        usleep(100000);
        $nc->close();

        $out = $errCh->pop();
        expect($out->getMessage())->toBe(Errors::ErrConnectionClosed->value);
        $time = microtime(true) - $start;

        // check if request take too much time to fail
        expect($time)->toBeLessThan(1);

        $rpc->call("App.Shutdown", "");
    });
});

it("should handle newRequestStyle", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $nc = newDefaultConnection();

        $response = "I will help you";
        $nc->subscribe("foo", function (Message $msg) use ($response, $nc) {
            $nc->publish($msg->reply, $response);
        });

        $err = null;
        try {
            $msg = $nc->request("foo", "help", 0.5);
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();
        expect($msg->data)->toBe($response);

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should handle request without body", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $nc = newDefaultConnection();

        $response = "I will help you";
        $nc->subscribe("foo", function (Message $msg) use ($response, $nc) {
            $nc->publish($msg->reply, $response);
        });

        $err = null;
        try {
            $msg = $nc->request("foo", null, 0.5);
        } catch (Throwable $t) {
            $err = $t;
        }
        expect($err)->toBeNull();
        expect($msg->data)->toBe($response);

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should handle simultaneous requests", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $nc = newDefaultConnection();

        $response = "I will help you";
        $nc->subscribe("foo", function (Message $msg) use ($response, $nc) {
            $nc->publish($msg->reply, $response);
        });

        $wg = new WaitGroup();
        $wg->add(50);
        $errCh = new Channel(50);

        for ($i = 0; $i < 50; $i++) {
            go(function () use ($wg, $errCh, $nc) {
                defer(fn() => $wg->done());

                try {
                    $nc->request("foo", null, 2);
                } catch (Exception $e) {
                    $errCh->push($e);
                }
            });
        }

        $wg->wait();

        expect(checkErrChannel($errCh))->not->toThrow(Exception::class);

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should handle close during request", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $nc = newDefaultConnection();

        $wg = new WaitGroup();
        $wg->add(1);

        go(function () use ($nc, $wg) {
            defer(fn() => $wg->done());
            usleep(100_000);
            $nc->close();
        });

        $nc->subscribeSync("foo");

        $err = null;
        try {
            $nc->request("foo", "help", 2);
        } catch (Exception $e) {
            $err = $e;
        }
        expect($err)->not->toBeNull();
        expect($err->getMessage())->toBeIn([Errors::ErrInvalidConnection->value, Errors::ErrConnectionClosed->value]);

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});

it("should handle close timeout on request with response queued", function () {
    $rpc = new Goridge\RPC\RPC(Goridge\Relay::create("tcp://rpcnats:6001"));
    Co\run(function () use ($rpc) {
        $rpc->call("App.RunDefaultServer", "");
        $nc = newDefaultConnection();

        $nc->subscribe("foo", function (Message $msg) use ($nc) {
            $nc->publish($msg->reply, "I will help you");
            $nc->close();
        });

        $err = null;
        try {
            $nc->request("foo", null, 1);
        } catch (Exception $e) {
            $err = $e;
        }
        expect($err)->not->toBeNull(); // ensure we get a timeout even if the response is queued

        $nc->close();
        $rpc->call("App.Shutdown", "");
    });
});
