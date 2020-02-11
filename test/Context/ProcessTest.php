<?php

namespace Amp\Parallel\Test\Context;

use Amp\Loop;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\Internal\ProcessHub;
use Amp\Parallel\Context\Process;

class ProcessTest extends AbstractContextTest
{
    public function createContext($script): Context
    {
        Loop::setState(Process::class, new ProcessHub(false)); // Manually set ProcessHub using socket server.
        return new Process($script);
    }
}
