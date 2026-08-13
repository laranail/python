<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Exceptions;

class ProcessDisabledException extends PythonException
{
    public static function make(): self
    {
        return new self(
            message: 'Running local Python scripts is disabled. It is arbitrary code execution '
                . 'reachable from configuration, so it requires a deliberate '
                . 'laranail.python.process.enabled = true.',
            code: 3003,
        );
    }
}
