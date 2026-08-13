<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\ValueObjects;

/**
 * What the caller asked for, before a transport was chosen.
 *
 * `target` is a service name, a `service:endpoint` pair, or a registered script
 * name — the manager decides which transport that resolves to.
 */
final readonly class PythonCall
{
    /**
     * @param array<array-key, mixed> $payload
     * @param list<string> $args
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $target,
        public array $payload = [],
        public ?int $timeout = null,
        public string $method = 'POST',
        public ?string $endpoint = null,
        public array $args = [],
        public ?string $correlationId = null,
        public array $metadata = [],
    ) {}

    public function withTimeout(int $seconds): self
    {
        return new self(
            $this->target, $this->payload, max(0, $seconds), $this->method,
            $this->endpoint, $this->args, $this->correlationId, $this->metadata,
        );
    }

    public function withCorrelationId(string $id): self
    {
        return new self(
            $this->target, $this->payload, $this->timeout, $this->method,
            $this->endpoint, $this->args, $id, $this->metadata,
        );
    }

    /** The service half of a "service:endpoint" target. */
    public function service(): string
    {
        return str_contains($this->target, ':')
            ? strstr($this->target, ':', true) ?: $this->target
            : $this->target;
    }

    /** The endpoint half, when the target carried one. */
    public function resolvedEndpoint(): ?string
    {
        if ($this->endpoint !== null) {
            return $this->endpoint;
        }

        if (! str_contains($this->target, ':')) {
            return null;
        }

        $endpoint = substr(strstr($this->target, ':') ?: '', 1);

        return $endpoint === '' ? null : $endpoint;
    }
}
