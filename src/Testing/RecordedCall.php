<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Testing;

use Simtabi\Laranail\Python\ValueObjects\PythonCall;
use Simtabi\Laranail\Python\ValueObjects\PythonResult;

/**
 * One call a fake intercepted, and what it answered.
 */
final readonly class RecordedCall
{
    public function __construct(
        public PythonCall $call,
        public PythonResult $result,
    ) {}
}
