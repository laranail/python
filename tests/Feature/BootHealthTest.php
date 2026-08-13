<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Tests\Feature;

use Simtabi\Laranail\Python\Bridge\PythonBridgeManager;
use Simtabi\Laranail\Python\Bridge\Transports\HttpTransport;
use Simtabi\Laranail\Python\Contracts\InterpreterResolver;
use Simtabi\Laranail\Python\Contracts\PythonHttpClient;
use Simtabi\Laranail\Python\Contracts\PythonProcessRunner;
use Simtabi\Laranail\Python\Contracts\ScriptResolver;
use Simtabi\Laranail\Python\Facades\Python;
use Simtabi\Laranail\Python\Process\Resolvers\AllowListScriptResolver;
use Simtabi\Laranail\Python\Process\Resolvers\RootClampScriptResolver;
use Simtabi\Laranail\Python\Support\PythonConfig;
use Simtabi\Laranail\Python\Tests\TestCase;

final class BootHealthTest extends TestCase
{
    public function test_every_binding_resolves_after_a_normal_boot(): void
    {
        foreach ([
            PythonConfig::class,
            PythonHttpClient::class,
            PythonProcessRunner::class,
            ScriptResolver::class,
            InterpreterResolver::class,
            HttpTransport::class,
            PythonBridgeManager::class,
        ] as $abstract) {
            self::assertTrue($this->app->bound($abstract), "{$abstract} is not bound.");
            self::assertNotNull($this->app->make($abstract));
        }
    }

    public function test_the_config_is_merged_under_the_namespaced_key(): void
    {
        self::assertSame('fastapi', config('laranail.python.default'));
        self::assertIsArray(config('laranail.python.services.fastapi'));
    }

    public function test_the_facade_resolves_the_manager(): void
    {
        self::assertInstanceOf(PythonBridgeManager::class, Python::getFacadeRoot());
    }

    public function test_the_allow_list_resolver_is_the_default(): void
    {
        self::assertInstanceOf(AllowListScriptResolver::class, $this->app->make(ScriptResolver::class));
    }

    public function test_the_root_clamp_resolver_is_opt_in(): void
    {
        config()->set('laranail.python.process.allow_arbitrary_paths', true);
        $this->app->forgetInstance(ScriptResolver::class);

        self::assertInstanceOf(RootClampScriptResolver::class, $this->app->make(ScriptResolver::class));
    }

    public function test_the_process_runner_is_bound_even_while_disabled(): void
    {
        self::assertFalse($this->app->make(PythonProcessRunner::class)->isEnabled());
        self::assertNotNull($this->app->make(PythonProcessRunner::class));
    }
}
