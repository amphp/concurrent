<?php

namespace Amp\Parallel\Context\Internal\Runner;

use Amp\Parallel\Context\Internal\ProcessHub;
use Amp\Process\Process as BaseProcess;
use Amp\Process\ProcessInputStream;
use Amp\Process\ProcessOutputStream;
use Amp\Promise;

final class ProcessRunner extends RunnerAbstract
{
    /** @var string|null Cached path to located PHP binary. */
    private static $binaryPath;

    /** @var \Amp\Process\Process */
    private $process;
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
    public function __construct($script, ProcessHub $hub, string $cwd = null, array $env = [], string $binary = null)
    {
        if ($binary === null) {
            if (\PHP_SAPI === "cli") {
                $binary = \PHP_BINARY;
            } else {
                $binary = self::$binaryPath ?? self::locateBinary();
            }
        } elseif (!\is_executable($binary)) {
            throw new \Error(\sprintf("The PHP binary path '%s' was not found or is not executable", $binary));
        }

        $options = [
            "html_errors" => "0",
            "display_errors" => "0",
            "log_errors" => "1",
        ];

        $runner = self::getScriptPath();

        // Monkey-patch the script path in the same way, only supported if the command is given as array.
        if (isset(self::$pharCopy) && \is_array($script) && isset($script[0])) {
            $script[0] = "phar://".self::$pharCopy.\substr($script[0], \strlen(\Phar::running(true)));
        }

        if (\is_array($script)) {
            $script = \implode(" ", \array_map("escapeshellarg", $script));
        } else {
            $script = \escapeshellarg($script);
        }


        $command = \implode(" ", [
            \escapeshellarg($binary),
            self::formatOptions($options),
            \escapeshellarg($runner),
            $hub->getUri(),
            $script,
        ]);

        $this->process = new BaseProcess($command, $cwd, $env);
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
                return self::$binaryPath = $path;
            }
        }

        throw new \Error("Could not locate PHP executable binary");
    }

    private static function formatOptions(array $options): string
    {
        $result = [];

        foreach ($options as $option => $value) {
            $result[] = \sprintf("-d%s=%s", $option, $value);
        }

        return \implode(" ", $result);
    }


    /**
     * Set process key.
     *
     * @param string $key Process key
     *
     * @return Promise
     */
    public function setProcessKey(string $key): Promise
    {
        return $this->process->getStdin()->write($key);
    }

    /**
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /**
     * Starts the process.
     *
     * @return Promise<int> Resolved with the PID
     */
    public function start(): Promise
    {
        return $this->process->start();
    }

    /**
     * Immediately kills the process.
     */
    public function kill(): void
    {
        $this->process->kill();
    }

    /**
     * @return \Amp\Promise<mixed> Resolves with the returned from the process.
     */
    public function join(): Promise
    {
        return $this->process->join();
    }

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
        $this->process->signal($signo);
    }

    /**
     * Returns the PID of the process.
     *
     * @see \Amp\Process\Process::getPid()
     *
     * @return int
     *
     * @throws \Amp\Process\StatusError
     */
    public function getPid(): int
    {
        return $this->process->getPid();
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
        return $this->process->getStdin();
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
        return $this->process->getStdout();
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
        return $this->process->getStderr();
    }
}
