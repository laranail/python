<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Events;

use Simtabi\Laranail\Python\ValueObjects\PythonCall;
use Simtabi\Laranail\Python\ValueObjects\PythonResult;

final readonly class PythonCallSucceeded
{
    public function __construct(public PythonCall $call, public PythonResult $result) {}
}
