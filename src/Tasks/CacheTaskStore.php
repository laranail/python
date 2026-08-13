<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Tasks;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Simtabi\Laranail\Python\Contracts\TaskStore;
use Simtabi\Laranail\Python\Enums\ErrorCode;
use Simtabi\Laranail\Python\Enums\Transport;
use Simtabi\Laranail\Python\Support\PythonConfig;
use Simtabi\Laranail\Python\ValueObjects\PythonResult;

/**
 * Cache-backed task state.
 *
 * The cache rather than a table because a task handle is short-lived by
 * definition — it exists between submit and completion — and a package that
 * ships a migration for that is asking every consumer to carry a schema for
 * something with a TTL. An application that wants durable history implements
 * {@see TaskStore} against its own table.
 *
 * Stored as plain arrays, not serialized objects, so a class rename cannot
 * strand rows that are already in flight.
 */
final readonly class CacheTaskStore implements TaskStore
{
    public function __construct(
        private CacheFactory $cache,
        private PythonConfig $config,
    ) {}

    public function put(TaskHandle $handle, ?PythonResult $result = null): void
    {
        $ttl = max(60, $this->config->int('tasks.ttl', 86_400));

        $this->store()->put($this->key($handle->id), $handle->toArray(), $ttl);

        if ($result instanceof PythonResult) {
            $this->store()->put($this->resultKey($handle->id), $result->toArray(), $ttl);
        }
    }

    public function find(string $id): ?TaskHandle
    {
        $raw = $this->store()->get($this->key($id));

        return is_array($raw) ? TaskHandle::fromArray($raw) : null;
    }

    public function result(string $id): ?PythonResult
    {
        $raw = $this->store()->get($this->resultKey($id));

        if (! is_array($raw)) {
            return null;
        }

        return new PythonResult(
            ok: (bool) ($raw['ok'] ?? false),
            data: is_array($raw['data'] ?? null) ? $raw['data'] : [],
            error: ErrorCode::tryFrom(is_scalar($raw['error'] ?? null) ? (string) $raw['error'] : ''),
            message: is_string($raw['message'] ?? null) ? $raw['message'] : null,
            status: is_numeric($raw['status'] ?? null) ? (int) $raw['status'] : null,
            exitCode: is_numeric($raw['exit_code'] ?? null) ? (int) $raw['exit_code'] : null,
            durationMs: is_numeric($raw['duration_ms'] ?? null) ? (float) $raw['duration_ms'] : 0.0,
            correlationId: is_string($raw['correlation_id'] ?? null) ? $raw['correlation_id'] : null,
            via: Transport::tryFrom(is_scalar($raw['via'] ?? null) ? (string) $raw['via'] : '') ?? Transport::Http,
        );
    }

    public function forget(string $id): void
    {
        $this->store()->forget($this->key($id));
        $this->store()->forget($this->resultKey($id));
    }

    private function store(): Repository
    {
        return $this->cache->store($this->config->stringOrNull('tasks.store'));
    }

    private function key(string $id): string
    {
        return 'laranail:python:task:' . $id;
    }

    private function resultKey(string $id): string
    {
        return 'laranail:python:task:' . $id . ':result';
    }
}
