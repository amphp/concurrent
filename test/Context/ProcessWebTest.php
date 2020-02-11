<?php

namespace Amp\Parallel\Test\Context;

use Amp\Delayed;
use Amp\Loop;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\Internal\ProcessHub;
use Amp\Parallel\Context\Process;
use Amp\Parallel\Sync\PanicError;
use Amp\PHPUnit\AsyncTestCase;
use Jelix\FakeServerConf\ApacheCGI;

class ProcessWebTest extends AsyncTestCase
{
    private static $proc;
    public static function setUpBeforeClass(): void
    {
        self::$proc = \proc_open(self::locateBinary()." -S localhost:8080", [2 => ['pipe', 'r']], $pipes, $root = \realpath(__DIR__.'/../../'), ['PHPRC' => '/tmp']);
        while (!@\file_get_contents('http://localhost:8080/composer.json')) {
            \usleep(500);
        }

        $server = new ApacheCGI($root);

        $file = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $file = \end($file)['file'];
        $file = \substr($file, \strlen($root));

        // now simulate an HTTP request
        $server->setHttpRequest("http://localhost:8080/$file?baz=2");
    }
    public static function tearDownAfterClass(): void
    {
        \proc_terminate(self::$proc);
    }

    private static function locateBinary(): string
    {
        $executable = \strncasecmp(\PHP_OS, "WIN", 3) === 0 ? "php.exe" : "php";

        $paths = \array_filter(\explode(\PATH_SEPARATOR, \getenv("PATH")));
        $paths[] = \PHP_BINDIR;
        $paths = \array_unique($paths);

        foreach ($paths as $path) {
            $path .= \DIRECTORY_SEPARATOR.$executable;
            if (\is_executable($path)) {
                return $path;
            }
        }

        throw new \Error("Could not locate PHP executable binary");
    }


    public function createContext($script): Context
    {
        Loop::setState(Process::class, new ProcessHub(false)); // Manually set ProcessHub using socket server.
        return new Process($script, null, [], null, true);
    }


    public function testBasicProcess()
    {
        $context = $this->createContext([
                __DIR__."/Fixtures/test-process.php",
                "Test"
            ]);
        yield $context->start();
        $this->assertSame("Test", yield $context->join());
    }

    public function testFailingProcess()
    {
        $this->expectException(PanicError::class);
        $this->expectExceptionMessage('No string provided');

        $context = $this->createContext(__DIR__."/Fixtures/test-process.php");
        yield $context->start();
        yield $context->join();
    }

    public function testThrowingProcessOnReceive()
    {
        $this->expectException(PanicError::class);
        $this->expectExceptionMessage('Test message');

        $context = $this->createContext(__DIR__."/Fixtures/throwing-process.php");
        yield $context->start();
        yield $context->receive();
    }

    public function testThrowingProcessOnSend()
    {
        $this->expectException(PanicError::class);
        $this->expectExceptionMessage('Test message');

        $context = $this->createContext(__DIR__."/Fixtures/throwing-process.php");
        yield $context->start();
        yield new Delayed(100);
        yield $context->send(1);
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

        $context = $this->createContext(__DIR__."/Fixtures/invalid-result-process.php");
        yield $context->start();
        \var_dump(yield $context->join());
    }

    public function testNoCallbackReturned()
    {
        $this->expectException(PanicError::class);
        $this->expectExceptionMessage('did not return a callable function');

        $context = $this->createContext(__DIR__."/Fixtures/no-callback-process.php");
        yield $context->start();
        \var_dump(yield $context->join());
    }

    public function testParseError()
    {
        $this->expectException(PanicError::class);
        $this->expectExceptionMessage('contains a parse error');

        $context = $this->createContext(__DIR__."/Fixtures/parse-error-process.inc");
        yield $context->start();
        yield $context->join();
    }
}
