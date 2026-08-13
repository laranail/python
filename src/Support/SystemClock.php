<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * The real clock.
 *
 * Bound to {@see ClockInterface} so the callback timestamp window can be tested
 * without sleeping, which is the only way to prove a replay outside the window
 * is actually refused.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
