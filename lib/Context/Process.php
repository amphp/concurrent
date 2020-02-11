<?php

namespace Amp\Parallel\Context;

use Amp\Loop;
use Amp\Parallel\Context\Internal\Runner\ProcessRunner;
use Amp\Parallel\Context\Internal\Runner\WebRunner;
use Amp\Parallel\Sync\ChannelException;
use Amp\Parallel\Sync\ExitResult;
use Amp\Parallel\Sync\SynchronizationError;
use Amp\Process\ProcessInputStream;
use Amp\Process\ProcessOutputStream;
use Amp\Promise;
use Amp\TimeoutException;
use function Amp\call;

final class Process implements Context
{
    const KEY_LENGTH = 32;

    /** @var Internal\ProcessHub */
    private $hub;

    /** @var Internal\Runner\RunnerAbstract */
    private $process;

    /** @var \Amp\Parallel\Sync\ChannelledSocket */
    private $channel;

    /**
     * Creates and starts the process at the given path using the optional PHP binary path.
     *
     * @param string|array $script Path to PHP script or array with first element as path and following elements options
     *     to the PHP script (e.g.: ['bin/worker', 'Option1Value', 'Option2Value'].
     * @param string|null  $cwd Working directory.
     * @param mixed[]      $env Array of environment variables.
     * @param string       $binary Path to PHP binary. Null will attempt to automatically locate the binary.
     *
     * @return Promise<Process>
     */
    public static function run($script, string $cwd = null, array $env = [], string $binary = null): Promise
    {
        $process = new self($script, $cwd, $env, $binary);
        return call(function () use ($process): \Generator {
            yield $process->start();
            return $process;
        });
    }

    /**
     * @param string|array $script Path to PHP script or array with first element as path and following elements options
     *     to the PHP script (e.g.: ['bin/worker', 'Option1Value', 'Option2Value'].
     * @param string|null  $cwd Working directory.
     * @param mixed[]      $env Array of environment variables.
     * @param string       $binary Path to PHP binary. Null will attempt to automatically locate the binary.
     * @param bool         $useWeb Whether to use the WebRunner by default
     *
     * @throws \Error If the PHP binary path given cannot be found or is not executable.
     */
    public function __construct($script, string $cwd = null, array $env = [], string $binary = null, bool $useWeb = false)
    {
        $this->hub = Loop::getState(self::class);
        if (!$this->hub instanceof Internal\ProcessHub) {
            $this->hub = new Internal\ProcessHub;
            Loop::setState(self::class, $this->hub);
        }

        try {
            if (!$useWeb) {
                $this->process = new ProcessRunner($script, $this->hub, $cwd, $env, $binary);
            }
        } catch (\Throwable $e) {
        }
        if (!$this->process) {
            $this->process = new WebRunner($script, $this->hub, $cwd, $env);
        }
    }


    /**
     * Private method to prevent cloning.
     */
    private function __clone()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function start(): Promise
    {
        return call(function (): \Generator {
            try {
                $pid = yield $this->process->start();

                yield $this->process->setProcessKey($this->hub->generateKey($pid, self::KEY_LENGTH));

                $this->channel = yield $this->hub->accept($pid);

                return $pid;
            } catch (\Throwable $exception) {
                $this->process->kill();
                throw new ContextException("Starting the process failed", 0, $exception);
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /**
     * {@inheritdoc}
     */
    public function receive(): Promise
    {
        if ($this->channel === null) {
            throw new StatusError("The process has not been started");
        }

        return call(function (): \Generator {
            try {
                $data = yield $this->channel->receive();
            } catch (ChannelException $e) {
                throw new ContextException("The process stopped responding, potentially due to a fatal error or calling exit", 0, $e);
            }

            if ($data instanceof ExitResult) {
                $data = $data->getResult();
                throw new SynchronizationError(\sprintf(
                    'Process unexpectedly exited with result of type: %s',
                    \is_object($data) ? \get_class($data) : \gettype($data)
                ));
            }

            return $data;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function send($data): Promise
    {
        if ($this->channel === null) {
            throw new StatusError("The process has not been started");
        }

        if ($data instanceof ExitResult) {
            throw new \Error("Cannot send exit result objects");
        }

        return call(function () use ($data): \Generator {
            try {
                return yield $this->channel->send($data);
            } catch (ChannelException $e) {
                if ($this->channel === null) {
                    throw new ContextException("The process stopped responding, potentially due to a fatal error or calling exit", 0, $e);
                }

                try {
                    $data = yield Promise\timeout($this->join(), 100);
                } catch (ContextException | ChannelException | TimeoutException $ex) {
                    if ($this->isRunning()) {
                        $this->kill();
                    }
                    throw new ContextException("The process stopped responding, potentially due to a fatal error or calling exit", 0, $e);
                }

                throw new SynchronizationError(\sprintf(
                    'Process unexpectedly exited with result of type: %s',
                    \is_object($data) ? \get_class($data) : \gettype($data)
                ), 0, $e);
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function join(): Promise
    {
        if ($this->channel === null) {
            throw new StatusError("The process has not been started");
        }

        return call(function (): \Generator {
            try {
                $data = yield $this->channel->receive();
            } catch (\Throwable $exception) {
                if ($this->isRunning()) {
                    $this->kill();
                }
                throw new ContextException("Failed to receive result from process", 0, $exception);
            }

            if (!$data instanceof ExitResult) {
                if ($this->isRunning()) {
                    $this->kill();
                }
                throw new SynchronizationError("Did not receive an exit result from process");
            }

            $this->channel->close();

            $code = yield $this->process->join();
            if ($code !== 0) {
                throw new ContextException(\sprintf("Process exited with code %d", $code));
            }


            return $data->getResult();
        });
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

    /**
     * {@inheritdoc}
     */
    public function kill(): void
    {
        $this->process->kill();

        if ($this->channel !== null) {
            $this->channel->close();
        }
    }
}
