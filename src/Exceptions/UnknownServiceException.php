<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Exceptions;

class UnknownServiceException extends PythonException
{
    /**
     * @param list<string> $known
     */
    public static function for(string $name, array $known = []): self
    {
        $suffix = $known === []
            ? 'No services are configured.'
            : 'Configured: ' . implode(', ', $known) . '.';

        return new self(
            message: "No Python service is configured as [{$name}]. {$suffix}",
            code: 3001,
            context: ['service' => $name, 'known' => $known],
        );
    }
}
