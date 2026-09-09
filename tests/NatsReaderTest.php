<?php

use LoungeUp\Nats\NatsReader;
use OpenSwoole\Coroutine\Client;

class FakeClient extends Client
{
    public function __construct(private array $chunks)
    {
        parent::__construct(OpenSwoole\Constant::SOCK_TCP);
    }

    public function recv(float $timeout = 0): string|bool
    {
        if (count($this->chunks) === 0) {
            $this->errCode = 104;
            $this->errMsg = "Connection reset by peer";
            return false;
        }

        return array_shift($this->chunks);
    }
}

function newReader(array $chunks): NatsReader
{
    return new NatsReader(r: new FakeClient($chunks), off: -1);
}

it("should keep the bytes following the delimiter for the next read", function () {
    co::run(function () {
        $info = "INFO {\"server_id\":\"x\",\"connect_info\":true} \r\n";
        $br = newReader(["PONG\r\n" . $info, "PING\r\n"]);

        [$line, $err] = $br->readString("\n");
        expect($err)->toBeNull();
        expect($line)->toBe("PONG\r\n");

        expect($br->read())->toBe($info);
        expect($br->read())->toBe("PING\r\n");
    });
});

it("should read a line split across several socket reads", function () {
    co::run(function () {
        $br = newReader(["PO", "NG\r", "\nINFO {}\r\n"]);

        [$line, $err] = $br->readString("\n");
        expect($err)->toBeNull();
        expect($line)->toBe("PONG\r\n");

        expect($br->read())->toBe("INFO {}\r\n");
    });
});

it("should read consecutive lines from a single socket read", function () {
    co::run(function () {
        $br = newReader(["+OK\r\nPONG\r\n"]);

        [$first] = $br->readString("\n");
        [$second] = $br->readString("\n");

        expect($first)->toBe("+OK\r\n");
        expect($second)->toBe("PONG\r\n");
    });
});

it("should return the socket error with the partial line", function () {
    co::run(function () {
        $br = newReader(["PON"]);

        [$line, $err] = $br->readString("\n");
        expect($line)->toBe("PON");
        expect($err)->toBeInstanceOf(Exception::class);
    });
});
