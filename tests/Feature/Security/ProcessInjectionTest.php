<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Tests\Feature\Security;

use Simtabi\Laranail\Python\Contracts\PythonProcessRunner;
use Simtabi\Laranail\Python\Contracts\ScriptResolver;
use Simtabi\Laranail\Python\Exceptions\InterpreterNotFoundException;
use Simtabi\Laranail\Python\Exceptions\ProcessDisabledException;
use Simtabi\Laranail\Python\Exceptions\ScriptNotAllowedException;
use Simtabi\Laranail\Python\Tests\TestCase;
use Simtabi\Laranail\Python\ValueObjects\ProcessCall;

/**
 * The process transport is arbitrary code execution reachable from config.
 * These are the guards that make it defensible, so they are asserted rather
 * than assumed.
 */
final class ProcessInjectionTest extends TestCase
{
    private string $sandbox;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // root/ and outside/ are SIBLINGS. An "outside" directory nested inside
        // the root would make every containment assertion pass for the wrong
        // reason.
        $this->sandbox = sys_get_temp_dir() . '/laranail-python-' . bin2hex(random_bytes(6));
        $this->root = $this->sandbox . '/root';

        mkdir($this->root . '/scripts', 0o755, true);
        mkdir($this->sandbox . '/outside', 0o755, true);

        // The interpreter under test is PHP_BINARY, so the fixtures are PHP.
        // The transport does not care what language it launches.
        file_put_contents(
            $this->root . '/scripts/echo.php',
            '<?php echo json_encode(["got" => json_decode(stream_get_contents(STDIN), true), "argv" => array_slice($argv, 1)]);',
        );
        file_put_contents($this->sandbox . '/outside/secret.php', '<?php echo "{}";');

        config()->set('laranail.python.process.enabled', true);
        config()->set('laranail.python.process.root', $this->root);
        config()->set('laranail.python.process.interpreters', ['default' => PHP_BINARY]);
        config()->set('laranail.python.process.scripts', [
            'echo' => 'scripts/echo.php',
            'escaping' => '../outside/secret.php',
            'missing' => 'scripts/nope.php',
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->sandbox));

        parent::tearDown();
    }

    private function resolver(): ScriptResolver
    {
        $this->app->forgetInstance(ScriptResolver::class);

        return $this->app->make(ScriptResolver::class);
    }

    // -----------------------------------------------------------------
    // The allow-list
    // -----------------------------------------------------------------

    public function test_a_caller_cannot_name_a_path(): void
    {
        $this->expectException(ScriptNotAllowedException::class);
        $this->expectExceptionCode(3101);

        $this->resolver()->resolve('scripts/echo.php');
    }

    public function test_a_traversal_payload_is_refused(): void
    {
        $this->expectException(ScriptNotAllowedException::class);

        $this->resolver()->resolve('../../etc/passwd');
    }

    public function test_an_absolute_path_is_refused(): void
    {
        $this->expectException(ScriptNotAllowedException::class);

        $this->resolver()->resolve('/etc/passwd');
    }

    public function test_a_registered_script_resolves_to_its_real_path(): void
    {
        $resolved = $this->resolver()->resolve('echo');

        self::assertSame(realpath($this->root . '/scripts/echo.php'), $resolved->path);
    }

    public function test_a_config_entry_escaping_the_root_is_refused(): void
    {
        // Config is trusted more than a caller, not blindly.
        $this->expectException(ScriptNotAllowedException::class);
        $this->expectExceptionCode(3102);

        $this->resolver()->resolve('escaping');
    }

    public function test_a_registered_but_missing_file_is_refused(): void
    {
        $this->expectException(ScriptNotAllowedException::class);
        $this->expectExceptionCode(3103);

        $this->resolver()->resolve('missing');
    }

    // -----------------------------------------------------------------
    // The root clamp
    // -----------------------------------------------------------------

    public function test_the_root_clamp_still_refuses_an_escape(): void
    {
        config()->set('laranail.python.process.allow_arbitrary_paths', true);

        $this->expectException(ScriptNotAllowedException::class);
        $this->expectExceptionCode(3102);

        $this->resolver()->resolve('../outside/secret.php');
    }

    public function test_the_root_clamp_refuses_a_symlink_pointing_outside(): void
    {
        // realpath() follows the link, which is the whole reason it is used
        // instead of a lexical normalisation.
        symlink($this->sandbox . '/outside/secret.php', $this->root . '/scripts/linked.php');

        config()->set('laranail.python.process.allow_arbitrary_paths', true);

        $this->expectException(ScriptNotAllowedException::class);
        $this->expectExceptionCode(3102);

        $this->resolver()->resolve('scripts/linked.php');
    }

    public function test_the_root_clamp_accepts_a_path_inside_the_root(): void
    {
        config()->set('laranail.python.process.allow_arbitrary_paths', true);

        self::assertSame(
            realpath($this->root . '/scripts/echo.php'),
            $this->resolver()->resolve('scripts/echo.php')->path,
        );
    }

    // -----------------------------------------------------------------
    // Arguments
    // -----------------------------------------------------------------

    public function test_a_flag_argument_is_refused_by_default(): void
    {
        $this->expectException(ScriptNotAllowedException::class);
        $this->expectExceptionCode(3104);

        $this->app->make(PythonProcessRunner::class)
            ->run(new ProcessCall('echo', args: ['--output=/etc/cron.d/x']));
    }

    public function test_a_script_may_opt_into_flags(): void
    {
        config()->set('laranail.python.process.scripts.echo', [
            'path' => 'scripts/echo.php',
            'allows_flags' => true,
        ]);

        $result = $this->app->make(PythonProcessRunner::class)
            ->run(new ProcessCall('echo', args: ['--verbose']));

        self::assertTrue($result->ok);
        self::assertSame(['--verbose'], $result->get('argv'));
    }

    // -----------------------------------------------------------------
    // Interpreter
    // -----------------------------------------------------------------

    public function test_a_bare_interpreter_name_is_refused(): void
    {
        config()->set('laranail.python.process.interpreters', ['default' => 'python3']);

        $this->expectException(InterpreterNotFoundException::class);
        $this->expectExceptionCode(3202);

        $this->resolver()->resolve('echo');
    }

    public function test_a_non_executable_interpreter_is_refused(): void
    {
        config()->set('laranail.python.process.interpreters', ['default' => $this->root . '/scripts/echo.php']);

        $this->expectException(InterpreterNotFoundException::class);
        $this->expectExceptionCode(3203);

        $this->resolver()->resolve('echo');
    }

    public function test_an_unknown_named_interpreter_is_refused(): void
    {
        config()->set('laranail.python.process.scripts.echo', [
            'path' => 'scripts/echo.php',
            'interpreter' => 'nonexistent',
        ]);

        $this->expectException(InterpreterNotFoundException::class);
        $this->expectExceptionCode(3201);

        $this->resolver()->resolve('echo');
    }

    // -----------------------------------------------------------------
    // The kill switch
    // -----------------------------------------------------------------

    public function test_running_is_refused_while_the_transport_is_disabled(): void
    {
        config()->set('laranail.python.process.enabled', false);

        $this->expectException(ProcessDisabledException::class);

        $this->app->make(PythonProcessRunner::class)
            ->run(new ProcessCall('echo'));
    }
}
