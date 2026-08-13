<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Enums;

/**
 * Where a submitted long-running task has got to.
 */
enum TaskStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
