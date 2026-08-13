<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Http;

use Illuminate\Contracts\Support\Arrayable;
use Simtabi\Laranail\Python\Enums\Transport;

/**
 * One service's health, with enough context to act on a "no".
 *
 * A bare boolean tells you something is wrong and nothing about what; the base
 * URL and TLS mode are what turn a red row in `doctor` into a fix.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class HealthReport implements Arrayable
{
    public function __construct(
        public string $name,
        public bool $healthy,
        public ?float $roundTripMs = null,
        public string $baseUrl = '',
        public string $tlsMode = '',
        public ?string $error = null,
        public Transport $via = Transport::Http,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'healthy' => $this->healthy,
            'round_trip_ms' => $this->roundTripMs,
            'base_url' => $this->baseUrl,
            'tls' => $this->tlsMode,
            'error' => $this->error,
            'via' => $this->via->value,
        ];
    }
}
