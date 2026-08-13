<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Exceptions;

class MissingBaseUrlException extends PythonException
{
    public static function for(string $name): self
    {
        return new self(
            message: "The [{$name}] Python service has no base_url. "
                . "Set laranail.python.services.{$name}.base_url.",
            code: 3002,
            context: ['service' => $name],
        );
    }
}
