<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Contracts;

use Simtabi\Laranail\Python\Exceptions\InterpreterNotFoundException;

/**
 * Resolves a named interpreter to a verified absolute path.
 */
interface InterpreterResolver
{
    /**
     * @throws InterpreterNotFoundException
     */
    public function resolve(?string $name = null): string;

    /** @return array<string, string> */
    public function all(): array;
}
