<?php

namespace Amp\Parallel\Context\Internal\Runner;

use Amp\ByteStream\ResourceOutputStream;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Context\Internal\ProcessHub;
use Amp\Promise;
use Amp\Success;

final class WebRunner extends RunnerAbstract
{
    /** @var string|null Cached path to the runner script. */
    private static $runPath;
    /**
     * PID.
     *
     * @var int
     */
    private $pid;
    /**
     * Initialization payload.
     *
     * @var array
     */
    private $params;
    /**
     * Whether the process is running.
     *
     * @var boolean
     */
    private $running = false;

    /**
     * Socket.
     *
     * @var ResourceOutputStream
     */
    private $res;
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
        if (!isset($_SERVER['SERVER_NAME'])) {
            throw new ContextException("Could not initialize web runner!");
        }

        if (!self::$runPath) {
            $uri = \parse_url('tcp://'.$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (\substr($uri, -1) === '/') { // http://example.com/path/ (assumed index.php)
                $uri .= 'index'; // Add fake file name
            }
            $uri = \str_replace('//', '/', $uri);

            $rootDir = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $rootDir = \end($rootDir)['file'] ?? '';
            if (!$rootDir) {
                throw new ContextException('Could not get entry file!');
            }
            $rootDir = \dirname($rootDir);
            $uriDir = \dirname($uri);

            if (\substr($rootDir, -\strlen($uriDir)) !== $uriDir) {
                throw new ContextException("Mismatch between absolute root dir ($rootDir) and URI dir ($uriDir)");
            }

            // Absolute root of (presumably) readable document root
            $localRootDir = \substr($rootDir, 0, \strlen($rootDir)-\strlen($uriDir)).DIRECTORY_SEPARATOR;

            $runPath = self::getScriptPath($localRootDir);

            if (\substr($runPath, 0, \strlen($localRootDir)) === $localRootDir) { // Process runner is within readable document root
                self::$runPath = \substr($runPath, \strlen($localRootDir)-1);
            } else {
                $contents = \file_get_contents(self::SCRIPT_PATH);
                $contents = \str_replace("__DIR__", \var_export($localRootDir, true), $contents);
                $suffix = \bin2hex(\random_bytes(10));
                $runPath = $localRootDir."/amp-process-runner-".$suffix.".php";
                \file_put_contents($runPath, $contents);

                self::$runPath = \substr($runPath, \strlen($localRootDir)-1);

                \register_shutdown_function(static function () use ($runPath): void {
                    @\unlink($runPath);
                });
            }

            self::$runPath = \str_replace(DIRECTORY_SEPARATOR, '/', self::$runPath);
            self::$runPath = \str_replace('//', '/', self::$runPath);
        }

        // Monkey-patch the script path in the same way, only supported if the command is given as array.
        if (isset(self::$pharCopy) && \is_array($script) && isset($script[0])) {
            $script[0] = "phar://".self::$pharCopy.\substr($script[0], \strlen(\Phar::running(true)));
        }

        if (!\is_array($script)) {
            $script = [$script];
        }
        \array_unshift($script, $hub->getUri());
        $this->params = [
            'cwd' => $cwd,
            'env' => $env,
            'argv' => $script
        ];
        $this->pid = \random_int(0, PHP_INT_MAX);
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
        return $this->pid;
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
        $this->params['key'] = $key;
        $params = \http_build_query($this->params);

        $address = ($_SERVER['HTTPS'] ?? false ? 'tls' : 'tcp').'://'.$_SERVER['SERVER_NAME'];
        $port = $_SERVER['SERVER_PORT'];

        $uri = self::$runPath.'?'.$params;

        $payload = "GET $uri HTTP/1.1\r\nHost: ${_SERVER['SERVER_NAME']}\r\n\r\n";

        // We don't care for results or timeouts here, PHP doesn't count IOwait time as execution time anyway
        // Technically should use amphp/socket, but I guess it's OK to not introduce another dependency just for a socket that will be used once.
        $this->res = new ResourceOutputStream(\fsockopen($address, $port));
        $this->running = true;
        return $this->res->write($payload);
    }

    /**
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Starts the process.
     *
     * @return Promise<int> Resolved with the PID
     */
    public function start(): Promise
    {
        return new Success($this->pid);
    }

    /**
     * Immediately kills the process.
     */
    public function kill(): void
    {
        if (isset($this->res)) {
            unset($this->res);
        }
        $this->isRunning = false;
    }

    /**
     * @return \Amp\Promise<mixed> Resolves with the returned from the process.
     */
    public function join(): Promise
    {
        return new Success(0);
    }
}
