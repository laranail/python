<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Events;

use Simtabi\Laranail\Python\Enums\RejectionReason;

/**
 * A delivery was refused.
 *
 * The reason lives here and not in the HTTP response, which is a bare 401 with
 * no detail. Telling a caller *which* check failed tells an attacker which knob
 * to turn next; the application still needs to know, so it learns here.
 */
final readonly class PythonCallbackRejected
{
    public function __construct(
        public RejectionReason $reason,
        public ?string $deliveryId = null,
        public ?string $ip = null,
    ) {}
}
