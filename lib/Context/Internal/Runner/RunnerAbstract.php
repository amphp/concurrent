<?php

namespace Amp\Parallel\Context\Internal\Runner;

use Amp\Parallel\Context\Internal\ProcessHub;
use Amp\Process\ProcessException;
use Amp\Process\ProcessInputStream;
use Amp\Process\ProcessOutputStream;
use Amp\Promise;

abstract class RunnerAbstract
{
    const SCRIPT_PATH = __DIR__ . "/process-runner.php";

    /** @var string|null External version of SCRIPT_PATH if inside a PHAR. */
    protected static $pharScriptPath;

    /** @var string|null PHAR path with a '.phar' extension. */
    protected static $pharCopy;

    protected static function getScriptPath(string $alternateTmpDir = '')
    {
        // Write process runner to external file if inside a PHAR,
        // because PHP can't open files inside a PHAR directly except for the stub.
        if (\strpos(self::SCRIPT_PATH, "phar://") === 0) {
            $alternateTmpDir = $alternateTmpDir ?: \sys_get_temp_dir();

            if (self::$pharScriptPath) {
                $scriptPath = self::$pharScriptPath;
            } else {
                $path = \dirname(self::SCRIPT_PATH);

                if (\substr(\Phar::running(false), -5) !== ".phar") {
                    self::$pharCopy = $alternateTmpDir . "/phar-" . \bin2hex(\random_bytes(10)) . ".phar";
                    \copy(\Phar::running(false), self::$pharCopy);

                    \register_shutdown_function(static function (): void {
                        @\unlink(self::$pharCopy);
                    });

                    $path = "phar://" . self::$pharCopy . "/" . \substr($path, \strlen(\Phar::running(true)));
                }

                $contents = \file_get_contents(self::SCRIPT_PATH);
                $contents = \str_replace("__DIR__", \var_export($path, true), $contents);
                $suffix = \bin2hex(\random_bytes(10));
                self::$pharScriptPath = $scriptPath = $alternateTmpDir . "/amp-process-runner-" . $suffix . ".php";
                \file_put_contents($scriptPath, $contents);

                \register_shutdown_function(static function (): void {
                    @\unlink(self::$pharScriptPath);
                });
            }
        } else {
            $scriptPath = self::SCRIPT_PATH;
        }
        return $scriptPath;
    }
    /**
     * Constructor.
     *
     * @param string|array $script Path to PHP script or array with first element as path and following elements options
     *     to the PHP script (e.g.: ['bin/worker', 'Option1Value', 'Option2Value'].
     * @param string $runPath      Path to process runner script
     * @param string $cwd          Current working directory
     * @param array  $env          Environment variables
     * @param string $binary       PHP binary path
     */
    abstract public function __construct($script, ProcessHub $hub, string $cwd = null, array $env = [], string $binary = null);

    /**
     * Set process key.
     *
     * @param string $key Process key
     *
     * @return Promise
     */
    abstract public function setProcessKey(string $key): Promise;

    /**
     * @return bool
     */
    abstract public function isRunning(): bool;

    /**
     * Starts the execution process.
     *
     * @return Promise<int> Resolves with the PID
     */
    abstract public function start(): Promise;

    /**
     * Immediately kills the process.
     */
    abstract public function kill(): void;

    /**
     * @return \Amp\Promise<mixed> Resolves with the returned from the process.
     */
    abstract public function join(): Promise;

    /**
     * Returns the PID of the process.
     *
     * @see \Amp\Process\Process::getPid()
     *
     * @return int
     *
     * @throws \Amp\Process\StatusError
     */
    abstract public function getPid(): int;

    /**
     * Send a signal to the process.
     *
     * @see \Amp\Process\Process::signal()
     *
     * @param int $signo
     *
     * @throws \Amp\Process\ProcessException
     * @throws \Amp\Process\StatusError
     */
    public function signal(int $signo): void
    {
        throw new ProcessException("Not supported!");
    }

    /**
     * Returns the STDIN stream of the process.
     *
     * @see \Amp\Process\Process::getStdin()
     *
     * @return ProcessOutputStream
     *
     * @throws \Amp\Process\StatusError
     */
    public function getStdin(): ProcessOutputStream
    {
        throw new ProcessException("Not supported!");
    }

    /**
     * Returns the STDOUT stream of the process.
     *
     * @see \Amp\Process\Process::getStdout()
     *
     * @return ProcessInputStream
     *
     * @throws \Amp\Process\StatusError
     */
    public function getStdout(): ProcessInputStream
    {
        throw new ProcessException("Not supported!");
    }

    /**
     * Returns the STDOUT stream of the process.
     *
     * @see \Amp\Process\Process::getStderr()
     *
     * @return ProcessInputStream
     *
     * @throws \Amp\Process\StatusError
     */
    public function getStderr(): ProcessInputStream
    {
        throw new ProcessException("Not supported!");
    }
}
