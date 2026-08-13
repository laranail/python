<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Events;

use Simtabi\Laranail\Python\Tasks\TaskHandle;

final readonly class PythonTaskSubmitted
{
    public function __construct(public TaskHandle $handle) {}
}
