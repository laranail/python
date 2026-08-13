<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Commands;

use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * Generate a minimal FastAPI service that already speaks this package's
 * conventions.
 *
 * Three files, capped deliberately. It exists because the health-contract shape
 * and the exact HMAC string are otherwise tribal knowledge that every consumer
 * gets wrong once — usually by signing a re-encoded payload rather than the
 * bytes actually sent, which works until the first non-ASCII character.
 *
 * It writes into the host application, never into vendor, and it creates no
 * virtualenv and installs nothing.
 */
final class MakeServiceCommand extends Command
{
    use SupportsNamespacedNames;

    protected $name = 'laranail::python.make-service';

    /** @var list<string> */
    protected array $commandAliases = ['python:make-service'];

    protected $description = 'Scaffold a FastAPI service wired to this package.';

    protected $signature = 'laranail::python.make-service
        {name : The service name, e.g. inference}
        {--port=8001 : The port the scaffold suggests}
        {--force : Overwrite an existing directory}';

    public function handle(Filesystem $files): int
    {
        $nameArg = $this->argument('name');
        $name = is_string($nameArg) ? trim($nameArg) : '';

        if ($name === '' || preg_match('/^[a-z][a-z0-9_-]*$/i', $name) !== 1) {
            $this->services->display()->error(
                'The service name must start with a letter and contain only letters, digits, hyphens or underscores.'
            );

            return self::FAILURE;
        }

        $target = base_path("python/services/{$name}");

        if ($files->isDirectory($target) && ! $this->option('force')) {
            $this->services->display()->error("[{$target}] already exists. Pass --force to overwrite.");

            return self::FAILURE;
        }

        $files->ensureDirectoryExists($target);

        $portOption = $this->option('port');
        $port = is_scalar($portOption) ? (string) $portOption : '8001';

        $replacements = [
            '{{ name }}' => $name,
            '{{ NAME }}' => strtoupper(str_replace('-', '_', $name)),
            '{{ port }}' => $port,
        ];

        foreach (['main.py', 'requirements.txt', 'README.md'] as $file) {
            $stub = __DIR__ . '/../../stubs/fastapi/' . $file . '.stub';

            $files->put(
                $target . '/' . $file,
                strtr($files->get($stub), $replacements),
            );
        }

        $display = $this->services->display();
        $display->success("Scaffolded python/services/{$name}.");

        $display->list([
            "cd python/services/{$name} && python -m venv .venv && . .venv/bin/activate",
            'pip install -r requirements.txt',
            "uvicorn main:app --reload --port {$port}",
            "Add a '{$name}' entry under laranail.python.services, then run laranail::python.doctor.",
        ], 'Next');

        return self::SUCCESS;
    }
}
