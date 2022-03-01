<?php

use LoungeUp\Nats\Connection;
use LoungeUp\Nats\ParseException;
use LoungeUp\Nats\ParseState;
use LoungeUp\Nats\ParserState;
use LoungeUp\Nats\ServerInfo;

use function LoungeUp\Nats\getDefaultOptions;

test("test async INFO", function () {
    Co\run(function () {
        $opts = getDefaultOptions();
        $c = new Connection($opts);
        $c->ps = new ParseState();

        expect($c->ps->state)->toBe(ParserState::OP_START);

        $info = "INFO {}\r\n";

        callMethod(Connection::class, "parse", $c, [substr($info, 0, 1)]);
        expect($c->ps->state)->toBe(ParserState::OP_I);

        callMethod(Connection::class, "parse", $c, [substr($info, 1, 1)]);
        expect($c->ps->state)->toBe(ParserState::OP_IN);

        callMethod(Connection::class, "parse", $c, [substr($info, 2, 1)]);
        expect($c->ps->state)->toBe(ParserState::OP_INF);

        callMethod(Connection::class, "parse", $c, [substr($info, 3, 1)]);
        expect($c->ps->state)->toBe(ParserState::OP_INFO);

        callMethod(Connection::class, "parse", $c, [substr($info, 4, 1)]);
        expect($c->ps->state)->toBe(ParserState::OP_INFO_SPC);

        callMethod(Connection::class, "parse", $c, [substr($info, 5)]);
        expect($c->ps->state)->toBe(ParserState::OP_START);

        callMethod(Connection::class, "parse", $c, [$info]);
        expect($c->ps->state)->toBe(ParserState::OP_START);

        callMethod(Connection::class, "parse", $c, ["info\t  \t {}\r\n"]);
        expect($c->ps->state)->toBe(ParserState::OP_START);

        $json = new stdClass();
        $json->server_id = "test";
        $json->host = "localhost";
        $json->port = 4222;
        $json->auth_required = true;
        $json->tls_required = true;
        $json->max_payload = 2 * 1024 * 1024;
        $json->connect_urls = ["localhost:5222", "localhost:6222"];

        $json = json_encode($json);

        $expectedServer = new ServerInfo($json);

        $c->opts->noRandomize = true;

        expect($c->ps->state)->toBe(ParserState::OP_START);

        $info = sprintf("INFO %s\r\n", $json);

        callMethod(Connection::class, "parse", $c, [$info]);
        expect($c->ps->state)->toBe(ParserState::OP_START);

        callMethod(Connection::class, "parse", $c, [substr($info, 0, 9)]);
        expect($c->ps->state)->toBe(ParserState::INFO_ARG);
        expect($c->ps->argBuf)->not->toBeNull();

        callMethod(Connection::class, "parse", $c, [substr($info, 9, 2)]);
        expect($c->ps->state)->toBe(ParserState::INFO_ARG);
        expect($c->ps->argBuf)->not->toBeNull();

        callMethod(Connection::class, "parse", $c, [substr($info, 11)]);
        expect($c->ps->state)->toBe(ParserState::OP_START);
        expect($c->ps->argBuf)->toBeNull();

        expect($c->info)->toEqual($expectedServer);

        $goodInfos = [
            "INFO {}\r\n",
            "INFO  {}\r\n",
            "INFO {} \r\n",
            "INFO { \"server_id\": \"test\"  }   \r\n",
            "INFO {\"connect_urls\":[]}\r\n",
        ];
        foreach ($goodInfos as $good) {
            $c->ps = new ParseState();
            callMethod(Connection::class, "parse", $c, [$good]);
            expect($c->ps->state)->toBe(ParserState::OP_START);
        }

        $wrongInfos = ["IxNFO {}\r\n", "INxFO {}\r\n", "INFxO {}\r\n", "INFOx {}\r\n", "INFO{}\r\n"];
        foreach ($wrongInfos as $wrong) {
            $c->ps = new ParseState();
            expect(fn() => callMethod(Connection::class, "parse", $c, [$wrong]))->toThrow(ParseException::class);
        }

        $info = "INFO {}";
        $c->ps = new ParseState();
        callMethod(Connection::class, "parse", $c, [$info]);
        expect($c->ps->state)->not->toBe(ParserState::OP_START);
    });
});
