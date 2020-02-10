<?php

namespace Amp\Parallel\Test\Context;

use Amp\Delayed;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Sync\PanicError;
use Amp\PHPUnit\AsyncTestCase;

abstract class AbstractContextTest extends AsyncTestCase
{
    abstract public function createContext($script): Context;

    public function testBasicProcess()
    {
        $context = $this->createContext([
                __DIR__ . "/Fixtures/test-process.php",
                "Test"
            ]);
        yield $context->start();
        $this->assertSame("Test", yield $context->join());
    }

    public function testFailingProcess()
    {
        $this->expectException(PanicError::class);
        $this->expectExceptionMessage('No string provided');

        $context = $this->createContext(__DIR__ . "/Fixtures/test-process.php");
        yield $context->start();
        yield $context->join();
    }

    public function testInvalidScriptPath()
    {
        $this->expectException(PanicError::class);
        $this->expectExceptionMessage("No script found at '../test-process.php'");

        $context = $this->createContext("../test-process.php");
        yield $context->start();
        yield $context->join();
    }

    public function testInvalidResult()
    {
        $this->expectException(PanicError::class);
        $this->expectExceptionMessage('The given data cannot be sent because it is not serializable');

        $context = $this->createContext(__DIR__ . "/Fixtures/invalid-result-process.php");
        yield $context->start();
        \var_dump(yield $context->join());
    }

    public function testNoCallbackReturned()
    {
        $this->expectException(PanicError::class);
        $this->expectExceptionMessage('did not return a callable function');

        $context = $this->createContext(__DIR__ . "/Fixtures/no-callback-process.php");
        yield $context->start();
        \var_dump(yield $context->join());
    }

    public function testParseError()
    {
        $this->expectException(PanicError::class);
        $this->expectExceptionMessage('contains a parse error');

        $context = $this->createContext(__DIR__ . "/Fixtures/parse-error-process.inc");
        yield $context->start();
        yield $context->join();
    }

    public function testKillWhenJoining()
    {
        $this->expectException(ContextException::class);
        $this->expectExceptionMessage('Failed to receive result');

        $context = $this->createContext([
                __DIR__ . "/Fixtures/delayed-process.php",
                5,
            ]);
        yield $context->start();
        yield new Delayed(100);
        $promise = $context->join();
        $context->kill();
        $this->assertFalse($context->isRunning());
        yield $promise;
    }

    public function testKillBusyContext()
    {
        $this->expectException(ContextException::class);
        $this->expectExceptionMessage('Failed to receive result');

        $context = $this->createContext(
            [
                __DIR__ . "/Fixtures/sleep-process.php",
                5,
            ]
        );
        yield $context->start();
        yield new Delayed(100);
        $promise = $context->join();
        $context->kill();
        $this->assertFalse($context->isRunning());
        yield $promise;
    }

    public function testExitingProcess()
    {
        $this->expectException(ContextException::class);
        $this->expectExceptionMessage('Failed to receive result');

        $context = $this->createContext([
                __DIR__ . "/Fixtures/exiting-process.php",
                5,
            ]);
        yield $context->start();
        yield $context->join();
    }
}
