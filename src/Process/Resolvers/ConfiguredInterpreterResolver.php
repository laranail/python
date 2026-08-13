<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Process\Resolvers;

use Simtabi\Laranail\Python\Contracts\InterpreterResolver;
use Simtabi\Laranail\Python\Exceptions\InterpreterNotFoundException;
use Simtabi\Laranail\Python\Support\PythonConfig;

/**
 * Resolves a named interpreter to a verified absolute path.
 *
 * A bare name like `python3` is refused unless `process.allow_path_lookup` is
 * on, because `$PATH` decides what `python3` means and that is not something
 * this package will guess at — the difference between the system interpreter
 * and a project virtualenv is every dependency the script needs.
 *
 * When no interpreter is configured the fallback is the conventional venv at
 * `{root}/.venv/bin/python`, which is what a Python project in the host repo
 * almost always has.
 */
final readonly class ConfiguredInterpreterResolver implements InterpreterResolver
{
    public function __construct(
        private PythonConfig $config,
        private string $root,
    ) {}

    public function resolve(?string $name = null): string
    {
        $name ??= 'default';
        $configured = $this->all()[$name] ?? null;

        if ($configured === null && $name !== 'default') {
            throw InterpreterNotFoundException::notConfigured($name);
        }

        $path = $configured ?? $this->conventionalVenv();

        if (! str_starts_with($path, '/') && ! $this->config->bool('process.allow_path_lookup', false)) {
            throw InterpreterNotFoundException::notAbsolute($path);
        }

        if (str_starts_with($path, '/') && (! is_file($path) || ! is_executable($path))) {
            throw InterpreterNotFoundException::notExecutable($path);
        }

        return $path;
    }

    public function all(): array
    {
        $out = [];

        foreach ($this->config->array('process.interpreters') as $name => $path) {
            if (is_string($name) && is_string($path) && trim($path) !== '') {
                $out[$name] = trim($path);
            }
        }

        return $out;
    }

    private function conventionalVenv(): string
    {
        return $this->root . '/.venv/bin/python';
    }
}
