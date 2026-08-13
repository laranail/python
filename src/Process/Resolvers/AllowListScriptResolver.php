<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Process\Resolvers;

use Simtabi\Laranail\Python\Contracts\InterpreterResolver;
use Simtabi\Laranail\Python\Contracts\ScriptResolver;
use Simtabi\Laranail\Python\Exceptions\ScriptNotAllowedException;
use Simtabi\Laranail\Python\Support\PythonConfig;
use Simtabi\Laranail\Python\ValueObjects\ResolvedScript;

/**
 * Resolves a **logical name** to a script, from a configured allow-list.
 *
 * The default resolver, and the reason `run('embed')` never accepts a path.
 * A caller cannot reach `../../.env` or `/tmp/whatever.py` because it cannot
 * express a path at all — the only thing it can say is a name somebody put in
 * config, which is the same property that makes the HTTP service registry safe.
 *
 * The resolved path is still confirmed to live under the configured root, so a
 * `..` in a *config* entry is caught too. Config is trusted more than a caller,
 * not blindly.
 */
final readonly class AllowListScriptResolver implements ScriptResolver
{
    public function __construct(
        private PythonConfig $config,
        private InterpreterResolver $interpreters,
        private string $root,
    ) {}

    public function resolve(string $script): ResolvedScript
    {
        $entry = $this->config->array('process.scripts')[$script] ?? null;

        if ($entry === null) {
            throw ScriptNotAllowedException::notRegistered($script, $this->names());
        }

        $entry = is_array($entry) ? $entry : ['path' => $entry];
        $relative = is_string($entry['path'] ?? null) ? $entry['path'] : '';

        if ($relative === '') {
            throw ScriptNotAllowedException::notRegistered($script, $this->names());
        }

        $path = $this->clampToRoot($relative);

        return new ResolvedScript(
            name: $script,
            path: $path,
            interpreter: $this->interpreters->resolve(
                is_string($entry['interpreter'] ?? null) ? $entry['interpreter'] : null,
            ),
            timeout: is_numeric($entry['timeout'] ?? null) ? (int) $entry['timeout'] : null,
            allowsFlags: (bool) ($entry['allows_flags'] ?? false),
            env: $this->env($entry['env'] ?? []),
        );
    }

    public function names(): array
    {
        $names = [];

        foreach (array_keys($this->config->array('process.scripts')) as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Confirm the file exists and resolves inside the root.
     *
     * `realpath()` is what makes this hold: it collapses `..` *and* follows
     * symlinks, so a link planted inside the root and pointing at `/etc` fails
     * the containment check rather than passing a lexical one.
     */
    private function clampToRoot(string $relative): string
    {
        $candidate = str_starts_with($relative, '/') ? $relative : $this->root . '/' . ltrim($relative, '/');

        $real = realpath($candidate);
        $realRoot = realpath($this->root);

        if ($real === false || ! is_file($real)) {
            throw ScriptNotAllowedException::unreadable($candidate);
        }

        if ($realRoot === false || ! str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) {
            throw ScriptNotAllowedException::outsideRoot($real, $this->root);
        }

        return $real;
    }

    /**
     * @return array<string, string>
     */
    private function env(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $env = [];

        foreach ($raw as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $env[$key] = (string) $value;
            }
        }

        return $env;
    }
}
