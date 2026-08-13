<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Commands;

use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;
use Simtabi\Laranail\Python\Bridge\PythonBridgeManager;
use Simtabi\Laranail\Python\Http\HealthReport;

/**
 * Health only, for a readiness probe or a CI gate.
 *
 * Kept separate from `doctor` because a readiness check should not print
 * configuration — it runs on a schedule, in a place where the output is
 * scraped rather than read.
 */
final class HealthCommand extends Command
{
    use SupportsNamespacedNames;

    protected $name = 'laranail::python.health';

    /** @var list<string> */
    protected array $commandAliases = ['python:health'];

    protected $description = 'Probe every configured Python service and exit non-zero if any is unhealthy.';

    protected $signature = 'laranail::python.health
        {--service= : Probe only this service}
        {--json : Emit the report as JSON}';

    public function handle(PythonBridgeManager $python): int
    {
        $only = $this->option('service');
        $reports = $python->healthAll();

        if (is_string($only) && $only !== '') {
            $reports = array_filter(
                $reports,
                static fn (string $name): bool => $name === $only,
                ARRAY_FILTER_USE_KEY,
            );

            if ($reports === []) {
                $this->services->display()->error("No service is configured as [{$only}].");

                return self::FAILURE;
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_map(static fn (HealthReport $r): array => $r->toArray(), $reports),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->services->display()->displayTable(
                ['Service', 'Health', 'RTT', 'Base URL'],
                array_map(static fn (HealthReport $r): array => [
                    $r->name,
                    $r->healthy ? 'healthy' : 'unhealthy',
                    $r->roundTripMs === null ? '—' : $r->roundTripMs . ' ms',
                    $r->baseUrl,
                ], array_values($reports)),
            );
        }

        foreach ($reports as $report) {
            if (! $report->healthy) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
