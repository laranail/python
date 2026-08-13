<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Commands;

use Illuminate\Process\Factory as ProcessFactory;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;
use Simtabi\Laranail\Python\Contracts\InterpreterResolver;
use Simtabi\Laranail\Python\Contracts\PythonHttpClient;
use Simtabi\Laranail\Python\Contracts\PythonProcessRunner;
use Simtabi\Laranail\Python\Contracts\ScriptResolver;
use Simtabi\Laranail\Python\Exceptions\PythonException;
use Simtabi\Laranail\Python\Support\PythonConfig;
use Throwable;

/**
 * Answers the questions you actually have during an incident.
 *
 * Not "is a URL set" — the config file always looks fine — but which services
 * are reachable, whether TLS is verifying or quietly turned off, whether a
 * configured auth scheme is missing its credential, and which interpreter a
 * script would really run under. Those are the arrangements that leave an
 * application looking completely healthy while failing.
 */
final class DoctorCommand extends Command
{
    use SupportsNamespacedNames;

    protected $name = 'laranail::python.doctor';

    /** @var list<string> */
    protected array $commandAliases = ['python:doctor'];

    protected $description = 'Report every configured Python service and script, and anything misconfigured.';

    public function handle(
        PythonConfig $config,
        PythonHttpClient $http,
        PythonProcessRunner $process,
        ScriptResolver $scripts,
        InterpreterResolver $interpreters,
        ProcessFactory $processes,
    ): int {
        $display = $this->services->display();
        $display->header('laranail/python');

        $problems = [];

        $display->keyValue([
            'Default service' => $config->string('default', 'fastapi'),
            'Services' => (string) count($http->names()),
            'Process transport' => $process->isEnabled() ? 'enabled' : 'disabled',
            'Callbacks' => $config->bool('callbacks.enabled', false) ? 'enabled' : 'disabled',
        ]);

        $problems = [...$problems, ...$this->reportServices($http)];
        $problems = [...$problems, ...$this->reportProcess($process, $scripts, $interpreters, $config, $processes)];
        $problems = [...$problems, ...$this->reportCallbacks($config)];

        foreach ($problems as $problem) {
            $display->error($problem);
        }

        if ($problems !== []) {
            return self::FAILURE;
        }

        $display->success('No problems found.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function reportServices(PythonHttpClient $http): array
    {
        $names = $http->names();

        if ($names === []) {
            return ['No services are configured under laranail.python.services.'];
        }

        $problems = [];
        $rows = [];

        foreach ($names as $name) {
            try {
                $definition = $http->definition($name);
            } catch (PythonException $e) {
                $problems[] = $e->getMessage();

                continue;
            }

            if (($problem = $definition->baseUrlProblem()) !== null) {
                $problems[] = "Service [{$name}]: {$problem}.";
            }

            if (! $definition->auth->isComplete()) {
                $problems[] = "Service [{$name}]: auth scheme [{$definition->auth->scheme->value}] "
                    . 'is configured without its credential, so requests would go out unauthenticated.';
            }

            if (! $definition->verifySsl) {
                $problems[] = "Service [{$name}]: TLS verification is off. Prefer a ca_cert, "
                    . 'which keeps verification on and trusts one extra root.';
            }

            if ($this->isMetadataAddress($definition->baseUrl)) {
                $problems[] = "Service [{$name}]: base_url points at a cloud metadata address.";
            }

            $report = $http->report($name);

            $rows[] = [
                $name,
                $definition->baseUrl,
                $definition->auth->scheme->value,
                $definition->tlsMode(),
                $report->healthy ? 'healthy' : 'unhealthy',
                $report->roundTripMs === null ? '—' : $report->roundTripMs . ' ms',
            ];

            if (! $report->healthy) {
                $problems[] = "Service [{$name}] is not answering its health contract: {$report->error}";
            }
        }

        if ($rows !== []) {
            $this->services->display()->displayTable(
                ['Service', 'Base URL', 'Auth', 'TLS', 'Health', 'RTT'],
                $rows,
            );
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function reportProcess(
        PythonProcessRunner $process,
        ScriptResolver $scripts,
        InterpreterResolver $interpreters,
        PythonConfig $config,
        ProcessFactory $processes,
    ): array {
        if (! $process->isEnabled()) {
            return [];
        }

        $problems = [];
        $rows = [];

        if ($config->bool('process.allow_arbitrary_paths', false)) {
            $problems[] = 'process.allow_arbitrary_paths is on: any path inside the root may be run. '
                . 'The allow-list is the safer default.';
        }

        if ($config->bool('process.inherit_env', false)) {
            $problems[] = 'process.inherit_env is on: the child receives the full parent environment, '
                . 'including APP_KEY and database credentials.';
        }

        foreach ($scripts->names() as $name) {
            try {
                $resolved = $scripts->resolve($name);
                $rows[] = [
                    $name,
                    $resolved->path,
                    $resolved->interpreter,
                    $this->pythonVersion($processes, $resolved->interpreter),
                ];
            } catch (Throwable $e) {
                $problems[] = "Script [{$name}]: " . $e->getMessage();
            }
        }

        if ($rows !== []) {
            $this->services->display()->displayTable(['Script', 'Path', 'Interpreter', 'Version'], $rows);
        }

        if ($scripts->names() === [] && ! $config->bool('process.allow_arbitrary_paths', false)) {
            $problems[] = 'The process transport is enabled but no scripts are registered.';
        }

        try {
            $interpreters->resolve();
        } catch (Throwable $e) {
            $problems[] = 'Default interpreter: ' . $e->getMessage();
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function reportCallbacks(PythonConfig $config): array
    {
        if (! $config->bool('callbacks.enabled', false)) {
            return [];
        }

        $secrets = $config->stringList('callbacks.secrets');

        $this->services->display()->keyValue([
            'Callback prefix' => $config->string('callbacks.prefix', 'api/python'),
            'Callback secrets' => $secrets === [] ? 'none' : count($secrets) . ' configured',
            'Tolerance' => $config->int('callbacks.tolerance', 300) . 's',
        ]);

        if ($secrets === []) {
            return ['Callbacks are enabled but no secret is configured, so every delivery is refused.'];
        }

        return [];
    }

    /**
     * Ask the interpreter what it is.
     *
     * Through illuminate/process with an array command, like everything else
     * here — the package never builds a command string, and an arch test holds
     * that line.
     */
    private function pythonVersion(ProcessFactory $processes, string $interpreter): string
    {
        if (! is_file($interpreter) || ! is_executable($interpreter)) {
            return 'unavailable';
        }

        $result = $processes->newPendingProcess()->timeout(5)->run([$interpreter, '--version']);

        $output = trim($result->output() . $result->errorOutput());

        return $output === '' ? 'unknown' : $output;
    }

    private function isMetadataAddress(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && in_array($host, ['169.254.169.254', 'metadata.google.internal'], true);
    }
}
