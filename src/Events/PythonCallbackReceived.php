<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Events;

use Simtabi\Laranail\Python\Callbacks\CallbackEnvelope;

final readonly class PythonCallbackReceived
{
    public function __construct(public CallbackEnvelope $envelope) {}
}
