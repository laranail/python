<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Events;

use Simtabi\Laranail\Python\ValueObjects\PythonCall;

final readonly class PythonCallStarted
{
    public function __construct(public PythonCall $call) {}
}
