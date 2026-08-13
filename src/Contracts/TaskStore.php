<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Contracts;

use Simtabi\Laranail\Python\Tasks\TaskHandle;
use Simtabi\Laranail\Python\ValueObjects\PythonResult;

/**
 * Where submitted tasks and their results live between requests.
 */
interface TaskStore
{
    public function put(TaskHandle $handle, ?PythonResult $result = null): void;

    public function find(string $id): ?TaskHandle;

    public function result(string $id): ?PythonResult;

    public function forget(string $id): void;
}
