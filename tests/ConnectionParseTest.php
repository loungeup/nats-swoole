<?php

use LoungeUp\Nats\Connection;
use LoungeUp\Nats\ConnectionStatus;
use LoungeUp\Nats\ParseException;
use LoungeUp\Nats\ParseState;
use LoungeUp\Nats\ParserState;

use function LoungeUp\Nats\getDefaultOptions;

it("should parse ping message", function () {
    co::run(function () {
        $this->c = new Connection(getDefaultOptions());
        $this->c->ps = new ParseState();

        $this->c->newReaderWriter();
        $this->c->bw->switchToPending();

        expect($this->c->ps->state)->toBe(ParserState::OP_START);

        callMethod(Connection::class, "parse", $this->c, ["P"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_P);

        callMethod(Connection::class, "parse", $this->c, ["I"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_PI);

        callMethod(Connection::class, "parse", $this->c, ["N"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_PIN);

        callMethod(Connection::class, "parse", $this->c, ["G"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_PING);

        callMethod(Connection::class, "parse", $this->c, ["\r"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_PING);

        callMethod(Connection::class, "parse", $this->c, ["\n"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_START);

        callMethod(Connection::class, "parse", $this->c, ["PING\r\n"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_START);

        // Should tolerate spaces
        callMethod(Connection::class, "parse", $this->c, ["PING  \r"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_PING);

        $this->c->ps->state = ParserState::OP_START;

        callMethod(Connection::class, "parse", $this->c, ["PING  \r    \n"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_START);

        // Should tolerate lowercase
        callMethod(Connection::class, "parse", $this->c, ["ping  \r    \n"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_START);
    });
});

it("should parse pong message", function () {
    co::run(function () {
        $this->c = new Connection(getDefaultOptions());
        $this->c->ps = new ParseState();

        $this->c->newReaderWriter();
        $this->c->bw->switchToPending();

        expect($this->c->ps->state)->toBe(ParserState::OP_START);

        callMethod(Connection::class, "parse", $this->c, ["P"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_P);

        callMethod(Connection::class, "parse", $this->c, ["O"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_PO);

        callMethod(Connection::class, "parse", $this->c, ["N"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_PON);

        callMethod(Connection::class, "parse", $this->c, ["G"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_PONG);

        callMethod(Connection::class, "parse", $this->c, ["\r"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_PONG);

        callMethod(Connection::class, "parse", $this->c, ["\n"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_START);

        callMethod(Connection::class, "parse", $this->c, ["PONG\r\n"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_START);

        // Should tolerate spaces
        callMethod(Connection::class, "parse", $this->c, ["PONG  \r"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_PONG);

        $this->c->ps->state = ParserState::OP_START;

        callMethod(Connection::class, "parse", $this->c, ["PONG  \r    \n"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_START);

        // Should tolerate lowercase
        callMethod(Connection::class, "parse", $this->c, ["pong  \r    \n"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_START);
    });
});

it("should parse error message", function () {
    co::run(function () {
        $this->c = new Connection(getDefaultOptions());
        $this->c->ps = new ParseState();
        $this->c->status = ConnectionStatus::CLOSED;
        $this->c->newReaderWriter();
        $this->c->bw->switchToPending();

        // This test focus on the parser only, not on the error handling
        expect($this->c->ps->state)->toBe(ParserState::OP_START);

        $expectedError = "'Any kind of error'";
        $error = "-ERR  $expectedError\r\n";
        callMethod(Connection::class, "parse", $this->c, [substr($error, 0, 1)]);
        expect($this->c->ps->state)->toBe(ParserState::OP_MINUS);

        callMethod(Connection::class, "parse", $this->c, [substr($error, 1, 1)]);
        expect($this->c->ps->state)->toBe(ParserState::OP_MINUS_E);

        callMethod(Connection::class, "parse", $this->c, [substr($error, 2, 1)]);
        expect($this->c->ps->state)->toBe(ParserState::OP_MINUS_ER);

        callMethod(Connection::class, "parse", $this->c, [substr($error, 3, 1)]);
        expect($this->c->ps->state)->toBe(ParserState::OP_MINUS_ERR);

        callMethod(Connection::class, "parse", $this->c, [substr($error, 4, 1)]);
        expect($this->c->ps->state)->toBe(ParserState::OP_MINUS_ERR_SPC);

        callMethod(Connection::class, "parse", $this->c, [substr($error, 5, 1)]);
        expect($this->c->ps->state)->toBe(ParserState::OP_MINUS_ERR_SPC);

        // check with split arg buffer
        callMethod(Connection::class, "parse", $this->c, [substr($error, 6, 1)]);
        expect($this->c->ps->state)->toBe(ParserState::MINUS_ERR_ARG);

        callMethod(Connection::class, "parse", $this->c, [substr($error, 7, 3)]);
        expect($this->c->ps->state)->toBe(ParserState::MINUS_ERR_ARG);

        callMethod(Connection::class, "parse", $this->c, [substr($error, 10, strlen($error) - 2 - 10)]);
        expect($this->c->ps->state)->toBe(ParserState::MINUS_ERR_ARG);

        expect($this->c->ps->argBuf)->toBeTruthy();
        expect($this->c->ps->argBuf)->toBe($expectedError);

        callMethod(Connection::class, "parse", $this->c, [substr($error, strlen($error) - 2), 2]);
        expect($this->c->ps->state)->toBe(ParserState::OP_START);

        // check without arg buffer
        callMethod(Connection::class, "parse", $this->c, ["-ERR 'Any error'\r\n"]);
        expect($this->c->ps->state)->toBe(ParserState::OP_START);
    });
});

it("should parse ok message", function () {
    co::run(function () {
        $this->c = new Connection(getDefaultOptions());
        $this->c->ps = new ParseState();
        expect($this->c->ps->state)->toBe(ParserState::OP_START);

        $proto = "+OKay\r\n";
        callMethod(Connection::class, "parse", $this->c, [substr($proto, 0, 1)]);
        expect($this->c->ps->state)->toBe(ParserState::OP_PLUS);

        callMethod(Connection::class, "parse", $this->c, [substr($proto, 1, 1)]);
        expect($this->c->ps->state)->toBe(ParserState::OP_PLUS_O);

        callMethod(Connection::class, "parse", $this->c, [substr($proto, 2, 1)]);
        expect($this->c->ps->state)->toBe(ParserState::OP_PLUS_OK);

        callMethod(Connection::class, "parse", $this->c, [substr($proto, 3)]);
        expect($this->c->ps->state)->toBe(ParserState::OP_START);
    });
});

it("should fail to parse incorrect message", function () {
    co::run(function () {
        $this->c = new Connection(getDefaultOptions());
        $this->c->ps = new ParseState();
        expect(fn() => callMethod(Connection::class, "parse", $this->c, [" PING"]))->toThrow(ParseException::class);
        $this->c->ps->state = ParserState::OP_START;
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["ZOO"]))->toThrow(ParseException::class);
        $this->c->ps->state = ParserState::OP_START;

        // Ping
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["Px"]))->toThrow(ParseException::class);
        $this->c->ps->state = ParserState::OP_START;
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["PIx"]))->toThrow(ParseException::class);
        $this->c->ps->state = ParserState::OP_START;
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["PINx"]))->toThrow(ParseException::class);
        $this->c->ps->state = ParserState::OP_START;
        // stop here, ping is tolerant to anything between PING and \n

        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["POx"]))->toThrow(ParseException::class);
        $this->c->ps->state = ParserState::OP_START;
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["PONx"]))->toThrow(ParseException::class);
        $this->c->ps->state = ParserState::OP_START;
        // stop here, pong is tolerant to anything between PONG and \n

        // message
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["Mx\r\n"]))->toThrow(ParseException::class);
        $this->c->ps->state = ParserState::OP_START;
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["MSx\r\n"]))->toThrow(ParseException::class);
        $this->c->ps->state = ParserState::OP_START;
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["MSGx\r\n"]))->toThrow(ParseException::class);
        $this->c->ps->state = ParserState::OP_START;
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["MSG \r\n"]))->toThrow(Exception::class);
        $this->c->ps->state = ParserState::OP_START;
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["MSG  foo\r\n"]))->toThrow(Exception::class);
        $this->c->ps->state = ParserState::OP_START;
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["MSG foo 1\r\n"]))->toThrow(Exception::class);
        $this->c->ps->state = ParserState::OP_START;
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["MSG foo bar 1\r\n"]))->toThrow(
            Exception::class,
        );
        $this->c->ps->state = ParserState::OP_START;
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["MSG foo bar 1 baz\r\n"]))->toThrow(
            Exception::class,
        );
        $this->c->ps->state = ParserState::OP_START;
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["MSG foo 1 bar baz\r\n"]))->toThrow(
            Exception::class,
        );
        $this->c->ps->state = ParserState::OP_START;

        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["+x\r\n"]))->toThrow(ParseException::class);
        $this->c->ps->state = ParserState::OP_START;
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["+Ox\r\n"]))->toThrow(ParseException::class);
        $this->c->ps->state = ParserState::OP_START;

        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["-x\r\n"]))->toThrow(ParseException::class);
        $this->c->ps->state = ParserState::OP_START;
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["-Ex\r\n"]))->toThrow(ParseException::class);
        $this->c->ps->state = ParserState::OP_START;
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["-ERx\r\n"]))->toThrow(ParseException::class);
        $this->c->ps->state = ParserState::OP_START;
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["-ERRx\r\n"]))->toThrow(ParseException::class);
    });
});

it("should parse split message", function () {
    co::run(function () {
        $this->c = new Connection(getDefaultOptions());
        $this->c->ps = new ParseState();
        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["MSG a\r\n"]))->toThrow(Exception::class);
        $this->c->ps = new ParseState();

        expect(fn() => callMethod(Connection::class, "parse", $this->c, ["MSG a b c\r\n"]))->toThrow(Exception::class);
        $this->c->ps = new ParseState();

        $expectedCount = 1;
        $expectedSize = 3;

        callMethod(Connection::class, "parse", $this->c, ["MSG a"]);
        expect($this->c->ps->argBuf)->not->toBeNull();
        callMethod(Connection::class, "parse", $this->c, [" 1 3\r\nf"]);
        expect($this->c->ps->ma->size)->toBe(3);
        expect($this->c->ps->ma->sid)->toBe(1);
        expect($this->c->ps->ma->subject)->toBe("a");
        expect($this->c->ps->msgBuf)->not->toBeNull();

        callMethod(Connection::class, "parse", $this->c, ["oo\r\n"]);
        expect($this->c->stats->inMsgs->get())->toBe($expectedCount);
        expect($this->c->stats->inBytes->get())->toBe($expectedSize);
        expect($this->c->ps->argBuf)->toBeNull();
        expect($this->c->ps->msgBuf)->toBeNull();

        callMethod(Connection::class, "parse", $this->c, ["MSG a 1 3\r\nfo"]);
        expect($this->c->ps->ma->size)->toBe(3);
        expect($this->c->ps->ma->sid)->toBe(1);
        expect($this->c->ps->ma->subject)->toBe("a");
        expect($this->c->ps->argBuf)->not->toBeNull();
        expect($this->c->ps->msgBuf)->not->toBeNull();

        $expectedCount++;
        $expectedSize += 3;

        callMethod(Connection::class, "parse", $this->c, ["o\r\n"]);
        expect($this->c->stats->inMsgs->get())->toBe($expectedCount);
        expect($this->c->stats->inBytes->get())->toBe($expectedSize);
        expect($this->c->ps->argBuf)->toBeNull();
        expect($this->c->ps->msgBuf)->toBeNull();

        callMethod(Connection::class, "parse", $this->c, ["MSG a 1 6\r\nfo"]);
        expect($this->c->ps->ma->size)->toBe(6);
        expect($this->c->ps->ma->sid)->toBe(1);
        expect($this->c->ps->ma->subject)->toBe("a");
        expect($this->c->ps->argBuf)->not->toBeNull();
        expect($this->c->ps->msgBuf)->not->toBeNull();

        callMethod(Connection::class, "parse", $this->c, ["ob"]);
        $expectedCount++;
        $expectedSize += 6;

        callMethod(Connection::class, "parse", $this->c, ["ar\r\n"]);
        expect($this->c->stats->inMsgs->get())->toBe($expectedCount);
        expect($this->c->stats->inBytes->get())->toBe($expectedSize);
        expect($this->c->ps->argBuf)->toBeNull();
        expect($this->c->ps->msgBuf)->toBeNull();

        // Let's have a msg that is bigger than the parser's scratch size.
        // Since we prepopulate the msg with 'foo', adding 3 to the size.
        $msgSize = 4199;
        callMethod(Connection::class, "parse", $this->c, [sprintf("MSG a 1 b %d\r\nfoo", $msgSize)]);
        expect($this->c->ps->ma->size)->toBe($msgSize);
        expect($this->c->ps->ma->sid)->toBe(1);
        expect($this->c->ps->ma->subject)->toBe("a");
        expect($this->c->ps->ma->reply)->toBe("b");
        expect($this->c->ps->argBuf)->not->toBeNull();
        expect($this->c->ps->msgBuf)->not->toBeNull();

        $expectedCount++;
        $expectedSize += $msgSize;

        $buff = "";
        for ($i = 0, $buffsize = $msgSize - 3; $i < $buffsize; $i++) {
            $buff .= chr(ord("a") + ($i % 26));
        }

        callMethod(Connection::class, "parse", $this->c, [$buff]);
        expect($this->c->ps->state)->toBe(ParserState::MSG_PAYLOAD);
        expect($this->c->ps->ma->size)->toBe($msgSize);
        expect(strlen($this->c->ps->msgBuf))->toBe($msgSize);

        for ($k = 3; $k < $this->c->ps->ma->size; $k++) {
            expect($this->c->ps->msgBuf[$k])->toBe(chr(ord("a") + (($k - 3) % 26)));
        }

        callMethod(Connection::class, "parse", $this->c, ["\r\n"]);
        expect($this->c->stats->inMsgs->get())->toBe($expectedCount);
        expect($this->c->stats->inBytes->get())->toBe($expectedSize);
        expect($this->c->ps->argBuf)->toBeNull();
        expect($this->c->ps->msgBuf)->toBeNull();
        expect($this->c->ps->state)->toBe(ParserState::OP_START);
    });
});
