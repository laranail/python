<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Commands;

use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;
use Simtabi\Laranail\Python\Bridge\PythonBridgeManager;
use Simtabi\Laranail\Python\Contracts\PythonProcessRunner;
use Simtabi\Laranail\Python\Support\Json;

/**
 * Invoke a registered script from the CLI.
 *
 * Replaces the "ssh in and run python by hand" step, which is where a wrong
 * interpreter or a missing environment variable usually gets discovered.
 */
final class RunCommand extends Command
{
    use SupportsNamespacedNames;

    protected $name = 'laranail::python.run';

    /** @var list<string> */
    protected array $commandAliases = ['python:run'];

    protected $description = 'Run a registered Python script with a JSON payload.';

    protected $signature = 'laranail::python.run
        {script : The registered script name}
        {--payload= : JSON payload delivered on stdin}
        {--json : Emit the raw result as JSON}';

    public function handle(PythonBridgeManager $python, PythonProcessRunner $process): int
    {
        if (! $process->isEnabled()) {
            $this->services->display()->error(
                'The process transport is disabled. It is arbitrary code execution reachable from '
                . 'configuration, so it needs a deliberate laranail.python.process.enabled = true.'
            );

            return self::FAILURE;
        }

        $scriptArg = $this->argument('script');
        $script = is_string($scriptArg) ? $scriptArg : '';
        $raw = $this->option('payload');
        $payload = is_string($raw) && trim($raw) !== '' ? Json::decode($raw) : [];

        $result = $python->run($script, $payload);

        if ($this->option('json')) {
            $this->line((string) json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($result->ok) {
            $this->services->display()->success("[{$script}] finished in {$result->durationMs} ms.");
            $this->line((string) json_encode($result->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->services->display()->error($result->message ?? 'The script failed.');
        }

        return $result->ok ? self::SUCCESS : self::FAILURE;
    }
}
