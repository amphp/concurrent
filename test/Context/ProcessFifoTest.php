<?php

namespace Amp\Parallel\Test\Context;

use Amp\Loop;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\Internal\ProcessHub;
use Amp\Parallel\Context\Process;

class ProcessFifoTest extends AbstractContextTest
{
    public function createContext($script): Context
    {
        if (\strncasecmp(\PHP_OS, "WIN", 3) === 0) {
            $this->markTestSkipped('FIFO pipes do not work on Windows');
        }

        Loop::setState(Process::class, new ProcessHub(true)); // Manually set ProcessHub using FIFO pipes.
        return new Process($script);
    }
}
