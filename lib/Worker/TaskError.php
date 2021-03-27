<?php

namespace Amp\Parallel\Worker;

/**
 * @deprecated TaskFailureError will be thrown from failed Tasks instead of this class.
 */
class TaskError extends \Error
{
    /** @var string Class name of error thrown from task. */
    private string $name;

    /** @var string Stack trace of the error thrown from task. */
    private string $trace;

    /**
     * @param string          $name The exception class name.
     * @param string          $message The panic message.
     * @param string          $trace The panic stack trace.
     * @param \Throwable|null $previous Previous exception.
     */
    public function __construct(string $name, string $message = '', string $trace = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->name = $name;
        $this->trace = $trace;
    }

    /**
     * @return string
     * @deprecated Use TaskFailureThrowable::getOriginalClassName() instead.
     *
     * Returns the class name of the error thrown from the task.
     *
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     * @deprecated Use TaskFailureThrowable::getOriginalTraceAsString() instead.
     *
     * Gets the stack trace at the point the error was thrown in the task.
     *
     */
    public function getWorkerTrace(): string
    {
        return $this->trace;
    }
}
