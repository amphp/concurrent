<?php

namespace Amp\Parallel\Context\Internal;

use Amp\Parallel\Context\Process;
use Amp\Parallel\Sync;
use Amp\Promise;
use function Amp\call;

\define("AMP_CONTEXT", "process");
\define("AMP_CONTEXT_ID", \getmypid());

// Doesn't exist in phpdbg...
if (\function_exists("cli_set_process_title")) {
    @\cli_set_process_title("amp-process");
}

(function (): void {
    $paths = [
        \dirname(__DIR__, 6)."/autoload.php",
        \dirname(__DIR__, 4)."/vendor/autoload.php",
    ];

    foreach ($paths as $path) {
        if (\file_exists($path)) {
            $autoloadPath = $path;
            break;
        }
    }

    if (!isset($autoloadPath)) {
        \trigger_error("Could not locate autoload.php in any of the following files: ".\implode(", ", $paths), E_USER_ERROR);
        exit(1);
    }

    require $autoloadPath;
})();

$fromWeb = false;
if (!isset($argv)) { // Running from web
    $argv = $_REQUEST['argv'] ?? [];
    \array_unshift($argv, __DIR__);
    $argc = \count($argv);
    $fromWeb = true;

    @\ini_set('html_errors', 0);
    @\ini_set('display_errors', 0);
    @\ini_set('log_errors', 1);
}

(function () use ($argc, $argv, $fromWeb): void {
    // Remove this scripts path from process arguments.
    --$argc;
    \array_shift($argv);

    if (!isset($argv[0])) {
        \trigger_error("No socket path provided", E_USER_ERROR);
        exit(1);
    }

    // Remove socket path from process arguments.
    --$argc;
    $uri = \array_shift($argv);

    $key = $fromWeb ? $_REQUEST['key'] : "";

    // Read random key from STDIN and send back to parent over IPC socket to authenticate.
    while (\strlen($key) < Process::KEY_LENGTH) {
        if (($chunk = \fread(\STDIN, Process::KEY_LENGTH)) === false || \feof(\STDIN)) {
            \trigger_error("Could not read key from parent", E_USER_ERROR);
            exit(1);
        }
        $key .= $chunk;
    }

    if (\strpos($uri, 'tcp://') === false && \strpos($uri, 'unix://') === false) {
        $suffix = \bin2hex(\random_bytes(10));
        $prefix = \sys_get_temp_dir()."/amp-".$suffix.".fifo";

        if (\strlen($prefix) > 0xFFFF) {
            \trigger_error("Prefix is too long!", E_USER_ERROR);
            exit(1);
        }

        $sockets = [
            $prefix."2",
            $prefix."1",
        ];
        foreach ($sockets as $k => &$socket) {
            if (!\posix_mkfifo($socket, 0777)) {
                \trigger_error("Could not create FIFO client socket", E_USER_ERROR);
                exit(1);
            }

            \register_shutdown_function(static function () use ($socket): void {
                @\unlink($socket);
            });

            if (!$socket = \fopen($socket, 'r+')) { // Open in r+w mode to prevent blocking if there is no reader
                \trigger_error("Could not open FIFO client socket", E_USER_ERROR);
                exit(1);
            }
        }

        if (!$tempSocket = \fopen($uri, 'r+')) { // Open in r+w mode to prevent blocking if there is no reader
            \trigger_error("Could not connect to FIFO server", E_USER_ERROR);
            exit(1);
        }
        \stream_set_blocking($tempSocket, false);
        \stream_set_write_buffer($tempSocket, 0);

        if (!\fwrite($tempSocket, \pack('v', \strlen($prefix)).$prefix)) {
            \trigger_error("Failure sending request to FIFO server", E_USER_ERROR);
            exit(1);
        }
        \fclose($tempSocket);
        $tempSocket = null;

        $channel = new Sync\ChannelledSocket(...$sockets);
    } else {
        if (!$socket = \stream_socket_client($uri, $errno, $errstr, 5, \STREAM_CLIENT_CONNECT)) {
            \trigger_error("Could not connect to IPC socket", E_USER_ERROR);
            exit(1);
        }
        $channel = new Sync\ChannelledSocket($socket, $socket);
    }


    try {
        Promise\wait($channel->send($key));
    } catch (\Throwable $exception) {
        \trigger_error("Could not send key to parent", E_USER_ERROR);
        exit(1);
    }

    if ($fromWeb) { // Set environment variables only after auth
        if (isset($_REQUEST['cwd'])) {
            \chdir($_REQUEST['cwd']);
        }
        if (isset($_REQUEST['env']) && \is_array($_REQUEST['env'])) {
            foreach ($_REQUEST['env'] as $key => $value) {
                @\putenv("$key=$value");
            }
        }
    }

    try {
        if (!isset($argv[0])) {
            throw new \Error("No script path given");
        }

        if (!\is_file($argv[0])) {
            throw new \Error(\sprintf("No script found at '%s' (be sure to provide the full path to the script)", $argv[0]));
        }

        try {
            // Protect current scope by requiring script within another function.
            $callable = (function () use ($argc, $argv): callable { // Using $argc so it is available to the required script.
                return require $argv[0];
            })();
        } catch (\TypeError $exception) {
            throw new \Error(\sprintf("Script '%s' did not return a callable function", $argv[0]), 0, $exception);
        } catch (\ParseError $exception) {
            throw new \Error(\sprintf("Script '%s' contains a parse error: ".$exception->getMessage(), $argv[0]), 0, $exception);
        }

        $result = new Sync\ExitSuccess(Promise\wait(call($callable, $channel)));
    } catch (\Throwable $exception) {
        $result = new Sync\ExitFailure($exception);
    }

    try {
        Promise\wait(call(function () use ($channel, $result): \Generator {
            try {
                yield $channel->send($result);
            } catch (Sync\SerializationException $exception) {
                // Serializing the result failed. Send the reason why.
                yield $channel->send(new Sync\ExitFailure($exception));
            }
        }));
    } catch (\Throwable $exception) {
        \trigger_error("Could not send result to parent; be sure to shutdown the child before ending the parent", E_USER_ERROR);
        exit(1);
    }
})();
