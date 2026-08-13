<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Simtabi\Laranail\Python\Tests\TestCase;

final class CommandsTest extends TestCase
{
    public function test_doctor_reports_a_problem_and_exits_non_zero(): void
    {
        // Nothing is listening, so the health probe fails.
        Http::fake(['*' => Http::response([], 500)]);

        $this->artisan('laranail::python.doctor')->assertExitCode(1);
    }

    public function test_doctor_is_clean_when_every_service_answers(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'healthy'], 200)]);

        $this->artisan('laranail::python.doctor')->assertExitCode(0);
    }

    public function test_doctor_flags_tls_verification_being_off(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'healthy'], 200)]);
        config()->set('laranail.python.services.fastapi.verify_ssl', false);

        $this->artisan('laranail::python.doctor')->assertExitCode(1);
    }

    public function test_doctor_flags_an_auth_scheme_missing_its_credential(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'healthy'], 200)]);
        config()->set('laranail.python.services.fastapi.auth', ['scheme' => 'bearer']);

        $this->artisan('laranail::python.doctor')->assertExitCode(1);
    }

    public function test_health_exits_non_zero_when_a_service_is_down(): void
    {
        Http::fake(['*' => Http::response([], 503)]);

        $this->artisan('laranail::python.health')->assertExitCode(1);
    }

    public function test_health_exits_zero_when_everything_answers(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'healthy'], 200)]);

        $this->artisan('laranail::python.health')->assertExitCode(0);
    }

    public function test_health_refuses_an_unknown_service(): void
    {
        $this->artisan('laranail::python.health', ['--service' => 'nope'])->assertExitCode(1);
    }

    public function test_run_refuses_while_the_process_transport_is_disabled(): void
    {
        $this->artisan('laranail::python.run', ['script' => 'anything'])->assertExitCode(1);
    }

    public function test_make_service_scaffolds_three_files(): void
    {
        $target = base_path('python/services/inference');
        File::deleteDirectory($target);

        $this->artisan('laranail::python.make-service', ['name' => 'inference'])->assertExitCode(0);

        self::assertFileExists($target . '/main.py');
        self::assertFileExists($target . '/requirements.txt');
        self::assertFileExists($target . '/README.md');
        self::assertStringContainsString('inference', (string) file_get_contents($target . '/main.py'));

        File::deleteDirectory(base_path('python'));
    }

    public function test_make_service_refuses_a_bad_name(): void
    {
        $this->artisan('laranail::python.make-service', ['name' => '../escape'])->assertExitCode(1);
    }

    public function test_make_service_refuses_to_overwrite_without_force(): void
    {
        $target = base_path('python/services/dup');
        File::ensureDirectoryExists($target);

        $this->artisan('laranail::python.make-service', ['name' => 'dup'])->assertExitCode(1);
        $this->artisan('laranail::python.make-service', ['name' => 'dup', '--force' => true])->assertExitCode(0);

        File::deleteDirectory(base_path('python'));
    }
}
