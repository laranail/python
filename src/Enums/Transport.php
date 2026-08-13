<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Enums;

/**
 * How a call reached Python.
 *
 * `Fake` is a case rather than a flag so `doctor` and `health` can report
 * "faked" instead of "healthy" — a fake left installed in a deployed path
 * should be loud, not silently green.
 */
enum Transport: string
{
    case Http = 'http';
    case Process = 'process';
    case Fake = 'fake';

    public function label(): string
    {
        return match ($this) {
            self::Http => 'HTTP service',
            self::Process => 'local process',
            self::Fake => 'faked',
        };
    }
}
