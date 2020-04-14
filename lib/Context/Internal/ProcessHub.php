<?php

namespace Amp\Parallel\Context\Internal;

use Amp\Deferred;
use Amp\Loop;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Sync\ChannelledSocket;
use Amp\Promise;
use Amp\TimeoutException;
use function Amp\call;

class ProcessHub
{
    const PROCESS_START_TIMEOUT = 5000;
    const KEY_RECEIVE_TIMEOUT = 1000;

    /** @var resource|null */
    private $server;

    /** @var string|null */
    private $uri;

    /** @var int[] */
    private $keys;

    /** @var string|null */
    private $watcher;

    /** @var Deferred[] */
    private $acceptor = [];

    /** @var string|null */
    private $toUnlink;

    /**
     * @param boolean $useFIFO Whether to use FIFOs instead of the more reliable UNIX socket server (CHOSEN AUTOMATICALLY, only for testing purposes)
     */
    public function __construct(bool $useFIFO = false)
    {
        $isWindows = \strncasecmp(\PHP_OS, "WIN", 3) === 0;

        if ($isWindows) {
            if ($useFIFO) {
                throw new \RuntimeException("Cannot use FIFOs on windows");
            }
            $this->uri = "tcp://127.0.0.1:0";
        } else {
            $suffix = \bin2hex(\random_bytes(10));
            $path = \sys_get_temp_dir() . "/amp-parallel-ipc-" . $suffix . ".sock";
            $this->uri = "unix://" . $path;
            $this->toUnlink = $path;
        }

        if (!$useFIFO) {
            $this->server = \stream_socket_server($this->uri, $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN);
        }

        $fifo = false;
        if (!$this->server) {
            if ($isWindows) {
                throw new \RuntimeException(\sprintf("Could not create IPC server: (Errno: %d) %s", $errno, $errstr));
            }
            if (!\posix_mkfifo($path, 0777)) {
                throw new \RuntimeException(\sprintf("Could not create the FIFO socket, and could not create IPC server: (Errno: %d) %s", $errno, $errstr));
            }
            if (!$this->server = \fopen($path, 'r+')) { // Open in r+w mode to prevent blocking if there is no reader
                throw new \RuntimeException(\sprintf("Could not connect to the FIFO socket, and could not create IPC server: (Errno: %d) %s", $errno, $errstr));
            }
            \stream_set_blocking($this->server, false);
            $fifo = true;
            $this->uri = $path;
        }

        if ($isWindows) {
            $name = \stream_socket_get_name($this->server, false);
            $port = \substr($name, \strrpos($name, ":") + 1);
            $this->uri = "tcp://127.0.0.1:" . $port;
        }

        $keys = &$this->keys;
        $acceptor = &$this->acceptor;
        $this->watcher = Loop::onReadable($this->server, static function (string $watcher, $server) use (&$keys, &$acceptor, $fifo): \Generator {
            if ($fifo) {
                $length = \unpack('v', \fread($server, 2))[1];
                if (!$length) {
                    return; // Could not accept, wrong length read
                }

                $prefix = \fread($server, $length);
                $sockets = [
                    $prefix . '1',
                    $prefix . '2',
                ];

                foreach ($sockets as $k => &$socket) {
                    if (@\filetype($socket) !== 'fifo') {
                        if ($k) {
                            \fclose($sockets[0]);
                        }
                        return; // Is not a FIFO
                    }

                    // Open in either read or write mode to send a close signal when done
                    if (!$socket = \fopen($socket, $k ? 'w' : 'r')) {
                        if ($k) {
                            \fclose($sockets[0]);
                        }
                        return; // Could not open fifo
                    }
                }
                $channel = new ChannelledSocket(...$sockets);
            } else {
                // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
                if (!$client = @\stream_socket_accept($server, 0)) {  // Timeout of 0 to be non-blocking.
                    return; // Accepting client failed.
                }
                $channel = new ChannelledSocket($client, $client);
            }

            try {
                $received = yield Promise\timeout($channel->receive(), self::KEY_RECEIVE_TIMEOUT);
            } catch (\Throwable $exception) {
                $channel->close();
                return; // Ignore possible foreign connection attempt.
            }

            if (!\is_string($received) || !isset($keys[$received])) {
                $channel->close();
                return; // Ignore possible foreign connection attempt.
            }

            $pid = $keys[$received];

            $deferred = $acceptor[$pid];
            unset($acceptor[$pid], $keys[$received]);
            $deferred->resolve($channel);
        });

        Loop::disable($this->watcher);
    }

    public function __destruct()
    {
        Loop::cancel($this->watcher);
        \fclose($this->server);
        if ($this->toUnlink !== null) {
            @\unlink($this->toUnlink);
        }
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function generateKey(int $pid, int $length): string
    {
        $key = \random_bytes($length);
        $this->keys[$key] = $pid;
        return $key;
    }

    public function accept(int $pid): Promise
    {
        return call(function () use ($pid): \Generator {
            $this->acceptor[$pid] = new Deferred;

            Loop::enable($this->watcher);

            try {
                $channel = yield Promise\timeout($this->acceptor[$pid]->promise(), self::PROCESS_START_TIMEOUT);
            } catch (TimeoutException $exception) {
                $key = \array_search($pid, $this->keys, true);
                \assert(\is_string($key), "Key for {$pid} not found");
                unset($this->acceptor[$pid], $this->keys[$key]);
                throw new ContextException("Starting the process timed out", 0, $exception);
            } finally {
                if (empty($this->acceptor)) {
                    Loop::disable($this->watcher);
                }
            }

            return $channel;
        });
    }
}
