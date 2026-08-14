<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Tests\Feature\Process;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Simtabi\Laranail\Python\Contracts\PythonProcessRunner;
use Simtabi\Laranail\Python\Enums\ErrorCode;
use Simtabi\Laranail\Python\Enums\Transport;
use Simtabi\Laranail\Python\Tests\TestCase;
use Simtabi\Laranail\Python\ValueObjects\ProcessCall;

/**
 * The process transport against a real Python interpreter.
 *
 * `ProcessInjectionTest` asserts the guards using PHP as the interpreter,
 * because what the transport launches is irrelevant to whether it refuses an
 * escaping path. This asserts the other half: that the thing it launches when
 * it does not refuse actually works, end to end, against the interpreter
 * consumers will really use.
 *
 * The two suites answer different questions and neither substitutes for the
 * other. A clamp proven only against fixtures PHP happens to run is a clamp
 * nobody has watched do its job.
 *
 * Excluded from the default suite and run by its own CI job across Python 3.11
 * and 3.13, since it needs an interpreter the developer machine may not have.
 */
#[Group('python')]
final class RealInterpreterTest extends TestCase
{
    private string $sandbox;

    private string $interpreter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->interpreter = $this->locateInterpreter();

        $this->sandbox = sys_get_temp_dir() . '/laranail-python-real-' . bin2hex(random_bytes(6));
        mkdir($this->sandbox . '/scripts', 0o755, true);

        $this->writeScript('echo.py', <<<'PY'
            import json, sys
            payload = json.load(sys.stdin)
            print(json.dumps({"echoed": payload}))
            PY);

        $this->writeScript('boom.py', <<<'PY'
            import sys
            sys.stderr.write("token=SUPERSECRET failed to connect\n")
            sys.exit(3)
            PY);

        $this->writeScript('env.py', <<<'PY'
            import json, os, sys
            json.load(sys.stdin)
            print(json.dumps({
                "app_key": os.environ.get("APP_KEY", ""),
                "allowed": os.environ.get("ALLOWED_VAR", ""),
            }))
            PY);

        $this->writeScript('argv.py', <<<'PY'
            import json, sys
            json.load(sys.stdin)
            print(json.dumps({"argv": sys.argv[1:]}))
            PY);

        $this->writeScript('sleep.py', <<<'PY'
            import time
            time.sleep(30)
            PY);

        config()->set('laranail.python.process', [
            'enabled' => true,
            'root' => $this->sandbox,
            'allow_arbitrary_paths' => false,
            'allow_path_lookup' => false,
            'timeout' => 20,
            'idle_timeout' => 10,
            'max_output_bytes' => 8_388_608,
            'log_stderr' => false,
            'stderr_max_chars' => 2000,
            'inherit_env' => false,
            'env' => ['ALLOWED_VAR' => 'i-am-allowed'],
            'interpreters' => ['default' => $this->interpreter],
            'scripts' => [
                'echo' => $this->sandbox . '/scripts/echo.py',
                'boom' => $this->sandbox . '/scripts/boom.py',
                'env' => $this->sandbox . '/scripts/env.py',
                'argv' => $this->sandbox . '/scripts/argv.py',
                'sleep' => $this->sandbox . '/scripts/sleep.py',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }

        parent::tearDown();
    }

    private function writeScript(string $name, string $body): void
    {
        // Heredocs here are indented for readability; Python is not forgiving
        // about that, so strip it back out.
        $lines = array_map(ltrim(...), explode("\n", $body));

        file_put_contents($this->sandbox . '/scripts/' . $name, implode("\n", $lines) . "\n");
    }

    private function locateInterpreter(): string
    {
        $configured = getenv('LARANAIL_PYTHON_BIN');

        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }

        $found = trim((string) shell_exec('command -v python3 2>/dev/null'));

        if ($found === '' || ! is_executable($found)) {
            self::markTestSkipped('No Python interpreter available. Set LARANAIL_PYTHON_BIN.');
        }

        return $found;
    }

    private function runner(): PythonProcessRunner
    {
        return $this->app->make(PythonProcessRunner::class);
    }

    // -----------------------------------------------------------------
    // It actually runs
    // -----------------------------------------------------------------

    #[Test]
    public function it_round_trips_a_payload_through_a_real_interpreter(): void
    {
        $result = $this->runner()->run(new ProcessCall(
            script: 'echo',
            payload: ['name' => 'laranail', 'n' => 42],
        ));

        self::assertTrue($result->ok, $result->message ?? 'run failed');
        self::assertSame(Transport::Process, $result->via);
        self::assertSame(['name' => 'laranail', 'n' => 42], $result->get('echoed'));
        self::assertSame(0, $result->exitCode);
    }

    #[Test]
    public function the_interpreter_under_test_is_the_one_configured(): void
    {
        // Guards the CI wiring itself. The workflow passes the matrix
        // interpreter through LARANAIL_PYTHON_BIN, and a missing `id:` on the
        // setup step once made that an empty string — so the job silently
        // tested whatever python3 happened to be on PATH instead of the
        // version it claimed to.
        $configured = getenv('LARANAIL_PYTHON_BIN');

        if (! is_string($configured) || $configured === '') {
            self::markTestSkipped('LARANAIL_PYTHON_BIN is not set; nothing to cross-check.');
        }

        self::assertSame($configured, $this->interpreter);
    }

    // -----------------------------------------------------------------
    // Failure surfaces as a result, not an exception
    // -----------------------------------------------------------------

    #[Test]
    public function a_non_zero_exit_becomes_a_failed_result(): void
    {
        $result = $this->runner()->run(new ProcessCall(script: 'boom'));

        self::assertTrue($result->failed());
        self::assertSame(3, $result->exitCode);
        self::assertNotNull($result->error);
    }

    #[Test]
    public function a_secret_on_stderr_does_not_reach_the_message(): void
    {
        // The script writes `token=SUPERSECRET` to stderr, which is exactly how
        // a traceback or a `requests` error leaks a credential.
        $result = $this->runner()->run(new ProcessCall(script: 'boom'));

        self::assertStringNotContainsString('SUPERSECRET', (string) $result->message);
        self::assertStringNotContainsString('SUPERSECRET', json_encode($result->data, JSON_THROW_ON_ERROR));
    }

    // -----------------------------------------------------------------
    // The environment clamp
    // -----------------------------------------------------------------

    #[Test]
    public function the_child_does_not_inherit_the_parent_environment(): void
    {
        // APP_KEY is set by the test harness. With inherit_env => false the
        // child must not see it — a Python script should not be able to read
        // the application's signing key just by being launched.
        putenv('APP_KEY=base64:this-should-not-leak');

        $result = $this->runner()->run(new ProcessCall(script: 'env'));

        self::assertTrue($result->ok, $result->message ?? 'run failed');
        self::assertSame('', $result->get('app_key'), 'APP_KEY reached the child process.');
        self::assertSame('i-am-allowed', $result->get('allowed'), 'The env allow-list did not apply.');
    }

    // -----------------------------------------------------------------
    // The payload never touches argv
    // -----------------------------------------------------------------

    #[Test]
    public function the_payload_goes_on_stdin_and_never_into_argv(): void
    {
        // /proc/<pid>/cmdline is world-readable, so anything on argv is
        // readable by every other user on the host for the process's lifetime.
        $result = $this->runner()->run(new ProcessCall(
            script: 'argv',
            payload: ['api_key' => 'SUPERSECRET'],
            args: ['verbose'],
        ));

        self::assertTrue($result->ok, $result->message ?? 'run failed');

        $argv = $result->get('argv');

        self::assertIsArray($argv);
        self::assertSame(['verbose'], $argv, 'Only the explicit args should reach argv.');
        self::assertNotContains('SUPERSECRET', $argv);
        self::assertStringNotContainsString(
            'SUPERSECRET',
            implode(' ', array_map(strval(...), $argv)),
            'The payload was passed on the command line.',
        );
    }

    // -----------------------------------------------------------------
    // Timeouts
    // -----------------------------------------------------------------

    #[Test]
    public function a_hung_script_is_killed_rather_than_hanging_the_request(): void
    {
        $started = hrtime(true);

        $result = $this->runner()->run(new ProcessCall(script: 'sleep', timeout: 2));

        $elapsed = (hrtime(true) - $started) / 1_000_000_000;

        self::assertTrue($result->failed());
        self::assertSame(ErrorCode::Timeout, $result->error);
        self::assertLessThan(
            20,
            $elapsed,
            'The 2s timeout did not stop a 30s sleep, so the clamp is not applied.',
        );
    }
}
