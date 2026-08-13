<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Contracts;

use Simtabi\Laranail\Python\Exceptions\ScriptNotAllowedException;
use Simtabi\Laranail\Python\ValueObjects\ResolvedScript;

/**
 * Turns a caller's script reference into something safe to execute, or refuses.
 *
 * There is no "best guess" here. Every failure is a refusal.
 */
interface ScriptResolver
{
    /**
     * @throws ScriptNotAllowedException
     */
    public function resolve(string $script): ResolvedScript;

    /** @return list<string> */
    public function names(): array;
}
