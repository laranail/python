<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Exceptions;

use RuntimeException;
use Simtabi\Laranail\Python\Support\Redactor;
use Throwable;

/**
 * Base exception for laranail/python.
 *
 * Carries a context array so a handler can log structure rather than re-parse a
 * message. Every message reaching here has already been through
 * {@see Redactor} where it could contain
 * anything the package injected.
 */
class PythonException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        protected array $context = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
