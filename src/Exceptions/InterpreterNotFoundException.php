<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Exceptions;

class InterpreterNotFoundException extends PythonException
{
    public static function notConfigured(string $name): self
    {
        return new self(
            message: "No Python interpreter is configured as [{$name}]. "
                . 'Add an absolute path under laranail.python.process.interpreters.',
            code: 3201,
            context: ['interpreter' => $name],
        );
    }

    public static function notAbsolute(string $path): self
    {
        return new self(
            message: "The interpreter [{$path}] is not an absolute path. A bare name would be "
                . 'resolved through $PATH, which decides what "python3" means and is not '
                . 'something this package will guess at.',
            code: 3202,
            context: ['path' => $path],
        );
    }

    public static function notExecutable(string $path): self
    {
        return new self(
            message: "The interpreter [{$path}] does not exist or is not executable.",
            code: 3203,
            context: ['path' => $path],
        );
    }
}
