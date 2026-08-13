<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\ValueObjects;

/**
 * A script that has passed every check and is safe to hand to the runner.
 *
 * Only a resolver constructs one. That is the point: the runner's signature
 * takes this type rather than a string, so there is no path that executes a
 * path nobody validated.
 */
final readonly class ResolvedScript
{
    /**
     * @param array<string, string> $env
     */
    public function __construct(
        public string $name,
        public string $path,
        public string $interpreter,
        public ?int $timeout = null,
        public bool $allowsFlags = false,
        public array $env = [],
    ) {}
}
