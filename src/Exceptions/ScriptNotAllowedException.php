<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Exceptions;

/**
 * A script could not be resolved to something safe to execute.
 *
 * Every case here is a refusal, never a fallback. There is no "best guess" for
 * which file to run.
 */
class ScriptNotAllowedException extends PythonException
{
    /**
     * @param list<string> $allowed
     */
    public static function notRegistered(string $name, array $allowed): self
    {
        $suffix = $allowed === []
            ? 'No scripts are registered.'
            : 'Registered: ' . implode(', ', $allowed) . '.';

        return new self(
            message: "No Python script is registered as [{$name}]. {$suffix} "
                . 'Scripts are named in laranail.python.process.scripts, not passed as paths.',
            code: 3101,
            context: ['script' => $name, 'allowed' => $allowed],
        );
    }

    public static function outsideRoot(string $path, string $root): self
    {
        return new self(
            message: "The script [{$path}] resolves outside the configured root [{$root}].",
            code: 3102,
            context: ['path' => $path, 'root' => $root],
        );
    }

    public static function unreadable(string $path): self
    {
        return new self(
            message: "The script [{$path}] does not exist or is not readable.",
            code: 3103,
            context: ['path' => $path],
        );
    }

    public static function flagArgument(string $argument): self
    {
        return new self(
            message: "The argument [{$argument}] looks like a flag. A caller-supplied flag can "
                . 'redirect a script\'s output or change what it does, so flags are refused '
                . 'unless the script sets allows_flags.',
            code: 3104,
            context: ['argument' => $argument],
        );
    }
}
