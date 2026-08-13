<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\ValueObjects;

use Closure;

/**
 * A request to run one registered script.
 *
 * There is no `command` string here, and that is the point: the runner builds
 * an array command, which bypasses the shell entirely, so a metacharacter in an
 * argument is an inert byte rather than a second command. A string field would
 * be the one thing a caller could misuse.
 */
final readonly class ProcessCall
{
    /**
     * @param array<array-key, mixed> $payload JSON, delivered on stdin
     * @param list<string> $args
     * @param (Closure(string, string): void)|null $onOutput
     */
    public function __construct(
        public string $script,
        public array $payload = [],
        public array $args = [],
        public ?int $timeout = null,
        public ?int $idleTimeout = null,
        public ?string $interpreter = null,
        public ?Closure $onOutput = null,
    ) {}
}
