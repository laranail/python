<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed reads of this package's configuration.
 *
 * `Repository::get()` returns `mixed`, so every call site either casts or lies
 * to the analyser. Casting at the call site is worse than it looks:
 * `(int) $config->get('…timeout')` turns a mistyped `'five'` into `0`, and a
 * zero timeout is a very different setting from a missing one. Reading through
 * here means a wrong type falls back to the documented default instead of
 * silently becoming a plausible-looking wrong value.
 *
 * Keys are relative to `laranail.python.`, because every key here is.
 */
final readonly class PythonConfig
{
    private const string PREFIX = 'laranail.python.';

    public function __construct(private Repository $config) {}

    public function string(string $key, string $default = ''): string
    {
        $value = $this->raw($key);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** Distinguishes "not set" from "set to empty", which several settings depend on. */
    public function stringOrNull(string $key): ?string
    {
        $value = $this->string($key);

        return $value === '' ? null : $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->raw($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->raw($key);

        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) ? (bool) $value : $default;
    }

    /**
     * @param array<array-key, mixed> $default
     * @return array<array-key, mixed>
     */
    public function array(string $key, array $default = []): array
    {
        $value = $this->raw($key);

        return is_array($value) ? $value : $default;
    }

    /**
     * A list of non-empty strings, with anything else dropped.
     *
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $out = [];

        foreach ($this->array($key) as $value) {
            if (is_string($value) && trim($value) !== '') {
                $out[] = trim($value);
            }
        }

        return $out;
    }

    public function has(string $key): bool
    {
        return $this->config->has(self::PREFIX . $key);
    }

    private function raw(string $key): mixed
    {
        return $this->config->get(self::PREFIX . $key);
    }
}
