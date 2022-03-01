<?php

include "vendor/autoload.php";

use LoungeUp\Nats\Connection;
use LoungeUp\Nats\Message;
use LoungeUp\Nats\Defaults;
use Swoole\Coroutine;
use Swoole\Coroutine\Client;
use Swoole\Coroutine\Scheduler;
use Swoole\Coroutine\System;
use Swoole\Process\Pool;
use Swoole\Runtime;

Runtime::enableCoroutine();
Coroutine::set(["hook_flags" => SWOOLE_HOOK_ALL, "socket_timeout" => -1, "enable_preemptive_scheduler" => true]);
Coroutine::enableScheduler();

$pool = new Pool(4);

$pool->on("WorkerStart", function ($pool, $workerId) {
    echo "Worker#{$workerId} is started\n";
    Co\run(function () {
        $client = Connection::createConnection("nats://127.0.0.1:4222");

        $client->queueSubscribe("access.invoices.>", "test", function (Message $message) use ($client) {
            echo "access\n";
            $out = [
                "result" => [
                    "get" => true,
                    "call" => "*",
                ],
            ];
            $message->respond(json_encode($out));
            //$client->flush();
        });

        $client->queueSubscribe("get.invoices.ping", "test", function (Message $message) use ($client) {
            echo "ping\n";
            $out = [
                "result" => [
                    "model" => [
                        "message" => "pong",
                    ],
                ],
            ];

            $message->respond(json_encode($out));
            //$client->flush();
        });
    });
});

$pool->on("WorkerStop", function ($pool, $workerId) {
    echo "Worker#{$workerId} is stopped\n";
});

$pool->start();

/*Co\run(function () {


    $client = Connection::createConnection("nats://127.0.0.1:4222");

    $client->subscribe("access.invoices.>", function (Message $message) use ($client) {
        echo "access\n";
        $out = [
            "result" => [
                "get" => true,
                "call" => "*",
            ],
        ];
        $message->respond(json_encode($out));
        //$client->flush();
    });

    $client->subscribe("get.invoices.ping", function (Message $message) use ($client) {
        echo "ping\n";
        $out = [
            "result" => [
                "model" => [
                    "message" => "pong",
                ],
            ],
        ];

        $message->respond(json_encode($out));
        //$client->flush();
    });

     go(function () {
        while (true) {
            sleep(5);
            foreach (Coroutine::list() as $c) {
                echo "CO $c\n";
                Coroutine::printBackTrace($c);
            }
        }
    }); 
});*/

/* $client = stream_socket_client('demo.nats.io:4222');

var_dump(stream_socket_recvfrom($client, 2048, STREAM_PEEK));
var_dump(stream_socket_recvfrom($client, 2048));
var_dump(stream_socket_recvfrom($client, 2048));
 */

/* Co\run(function() { 
    $id = go(function(){
        $id = Coroutine::getCid();
        echo "start coro $id\n";
        usleep(1);
        echo "resume coro $id @1\n";
        usleep(1);
        echo "resume coro $id @2\n";
    });
    echo "start to resume $id @1\n";
    sleep(1);
    echo "start to resume $id @2\n";
    sleep(2);
    echo "main\n";
}); */
