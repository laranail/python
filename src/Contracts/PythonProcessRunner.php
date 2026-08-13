<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Contracts;

use Simtabi\Laranail\Python\Exceptions\ProcessDisabledException;
use Simtabi\Laranail\Python\ValueObjects\ProcessCall;
use Simtabi\Laranail\Python\ValueObjects\PythonResult;

/**
 * The wide process seam: run a registered script and read its JSON back.
 *
 * Always bound, even when the transport is disabled — a binding that appears
 * and disappears with configuration is one that eventually fails at resolve
 * time in the one code path nobody exercised. Disabled means `run()` throws
 * {@see ProcessDisabledException}, which says so.
 */
interface PythonProcessRunner
{
    /**
     * @throws ProcessDisabledException when the transport is disabled
     */
    public function run(ProcessCall $call): PythonResult;

    /**
     * Logical names of every registered script.
     *
     * @return list<string>
     */
    public function scripts(): array;

    public function isEnabled(): bool;
}
