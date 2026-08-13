<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Simtabi\Laranail\Python\Enums\ErrorCode;
use Simtabi\Laranail\Python\Enums\Transport;
use Simtabi\Laranail\Python\Exceptions\PythonException;

/**
 * The outcome of one call, in a shape both transports can honestly fill.
 *
 * HTTP knows a status code and a process knows an exit code; neither knows the
 * other's, so both are nullable rather than being forced into one field that
 * would mean different things depending on how the call happened to travel.
 *
 * `message` is already redacted by the time it lands here.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class PythonResult implements Arrayable
{
    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(
        public bool $ok,
        public array $data = [],
        public ?ErrorCode $error = null,
        public ?string $message = null,
        public ?int $status = null,
        public ?int $exitCode = null,
        public float $durationMs = 0.0,
        public ?string $correlationId = null,
        public Transport $via = Transport::Http,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function ok(array $data = [], Transport $via = Transport::Http): self
    {
        return new self(ok: true, data: $data, via: $via);
    }

    public static function failure(
        ErrorCode $error,
        ?string $message = null,
        Transport $via = Transport::Http,
    ): self {
        return new self(ok: false, error: $error, message: $message, via: $via);
    }

    public function failed(): bool
    {
        return ! $this->ok;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    public function has(string $key): bool
    {
        return Arr::has($this->data, $key);
    }

    /**
     * Return the result, or throw when it failed.
     *
     * The default is to return rather than throw: a health probe and a batch
     * loop both want to inspect a failure, not catch one. Callers that would
     * rather not branch add `->throw()`.
     */
    public function throw(): self
    {
        if ($this->ok) {
            return $this;
        }

        throw new PythonException(
            $this->message ?? 'The Python call failed: ' . $this->describeError(),
            code: 3500,
            context: $this->toArray(),
        );
    }

    /**
     * A human label for the failure, for when no message was supplied.
     */
    private function describeError(): string
    {
        return $this->error instanceof ErrorCode ? $this->error->value : 'unknown';
    }

    public function withDuration(float $ms): self
    {
        return new self(
            $this->ok, $this->data, $this->error, $this->message,
            $this->status, $this->exitCode, round($ms, 2), $this->correlationId, $this->via,
        );
    }

    public function withCorrelationId(?string $id): self
    {
        return new self(
            $this->ok, $this->data, $this->error, $this->message,
            $this->status, $this->exitCode, $this->durationMs, $id, $this->via,
        );
    }

    public function withTransport(Transport $via): self
    {
        return new self(
            $this->ok, $this->data, $this->error, $this->message,
            $this->status, $this->exitCode, $this->durationMs, $this->correlationId, $via,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'data' => $this->data,
            'error' => $this->error?->value,
            'message' => $this->message,
            'status' => $this->status,
            'exit_code' => $this->exitCode,
            'duration_ms' => $this->durationMs,
            'correlation_id' => $this->correlationId,
            'via' => $this->via->value,
        ];
    }
}
