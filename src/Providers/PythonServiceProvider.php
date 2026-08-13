<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Providers;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Route;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Simtabi\Laranail\Package\Tools\Package;
use Simtabi\Laranail\Package\Tools\Providers\PackageServiceProvider;
use Simtabi\Laranail\Python\Bridge\PythonBridgeManager;
use Simtabi\Laranail\Python\Bridge\Transports\HttpTransport;
use Simtabi\Laranail\Python\Callbacks\CacheReplayGuard;
use Simtabi\Laranail\Python\Callbacks\HmacCallbackVerifier;
use Simtabi\Laranail\Python\Commands\DoctorCommand;
use Simtabi\Laranail\Python\Commands\HealthCommand;
use Simtabi\Laranail\Python\Commands\InstallCommand;
use Simtabi\Laranail\Python\Commands\MakeServiceCommand;
use Simtabi\Laranail\Python\Commands\RunCommand;
use Simtabi\Laranail\Python\Contracts\CallbackVerifier;
use Simtabi\Laranail\Python\Contracts\InterpreterResolver;
use Simtabi\Laranail\Python\Contracts\PythonHttpClient;
use Simtabi\Laranail\Python\Contracts\PythonProcessRunner;
use Simtabi\Laranail\Python\Contracts\ReplayGuard;
use Simtabi\Laranail\Python\Contracts\ScriptResolver;
use Simtabi\Laranail\Python\Contracts\TaskStore;
use Simtabi\Laranail\Python\Http\Middleware\VerifyPythonSignature;
use Simtabi\Laranail\Python\Http\PythonHttpClientService;
use Simtabi\Laranail\Python\Process\PythonProcessRunnerService;
use Simtabi\Laranail\Python\Process\Resolvers\AllowListScriptResolver;
use Simtabi\Laranail\Python\Process\Resolvers\ConfiguredInterpreterResolver;
use Simtabi\Laranail\Python\Process\Resolvers\RootClampScriptResolver;
use Simtabi\Laranail\Python\Support\PythonConfig;
use Simtabi\Laranail\Python\Support\SystemClock;
use Simtabi\Laranail\Python\Tasks\CacheTaskStore;

final class PythonServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laranail/python')
            ->setPublishTagId('python')
            ->hasConfigFile('python')
            ->hasCommands([
                DoctorCommand::class,
                HealthCommand::class,
                RunCommand::class,
                InstallCommand::class,
                MakeServiceCommand::class,
            ]);
    }

    #[Override]
    public function packageRegistered(): void
    {
        $this->app->singleton(
            PythonConfig::class,
            static fn (Application $app): PythonConfig => new PythonConfig($app->make(ConfigRepository::class)),
        );

        $this->app->singleton(ClockInterface::class, SystemClock::class);

        $this->app->singleton(
            InterpreterResolver::class,
            fn (Application $app): InterpreterResolver => new ConfiguredInterpreterResolver(
                $app->make(PythonConfig::class),
                $this->processRoot($app->make(PythonConfig::class)),
            ),
        );

        // Which resolver is installed IS the security posture, so it is decided
        // once here rather than branched on at every call.
        $this->app->singleton(ScriptResolver::class, function (Application $app): ScriptResolver {
            $config = $app->make(PythonConfig::class);
            $root = $this->processRoot($config);

            return $config->bool('process.allow_arbitrary_paths', false)
                ? new RootClampScriptResolver($config, $app->make(InterpreterResolver::class), $root)
                : new AllowListScriptResolver($config, $app->make(InterpreterResolver::class), $root);
        });

        $this->app->singleton(
            PythonHttpClient::class,
            static fn (Application $app): PythonHttpClient => new PythonHttpClientService(
                $app->make(PythonConfig::class),
                $app->make(HttpFactory::class),
                $app->make(LoggerInterface::class),
            ),
        );

        // Bound even when disabled. A binding that appears and disappears with
        // configuration is one that eventually fails at resolve time in the one
        // path nobody tested; disabled means run() throws, which says so.
        $this->app->singleton(
            PythonProcessRunner::class,
            static fn (Application $app): PythonProcessRunner => new PythonProcessRunnerService(
                $app->make(PythonConfig::class),
                $app->make(ScriptResolver::class),
                $app->make(ProcessFactory::class),
                $app->make(LoggerInterface::class),
            ),
        );

        $this->app->singleton(
            HttpTransport::class,
            static fn (Application $app): HttpTransport => new HttpTransport(
                $app->make(PythonHttpClient::class),
                $app->make(PythonConfig::class),
            ),
        );

        $this->app->singleton(
            ReplayGuard::class,
            static fn (Application $app): ReplayGuard => new CacheReplayGuard(
                $app->make(CacheFactory::class),
                $app->make(PythonConfig::class),
            ),
        );

        $this->app->singleton(
            CallbackVerifier::class,
            static fn (Application $app): CallbackVerifier => new HmacCallbackVerifier(
                $app->make(PythonConfig::class),
                $app->make(ReplayGuard::class),
                $app->make(ClockInterface::class),
            ),
        );

        $this->app->singleton(
            TaskStore::class,
            static fn (Application $app): TaskStore => new CacheTaskStore(
                $app->make(CacheFactory::class),
                $app->make(PythonConfig::class),
            ),
        );

        $this->app->singleton(
            PythonBridgeManager::class,
            static fn (Application $app): PythonBridgeManager => new PythonBridgeManager(
                $app->make(PythonConfig::class),
                $app->make(PythonHttpClient::class),
                $app->make(PythonProcessRunner::class),
                $app->make(HttpTransport::class),
                $app->make(TaskStore::class),
                $app->make(Dispatcher::class),
            ),
        );

        $this->app->alias(PythonBridgeManager::class, 'python');
    }

    #[Override]
    public function packageBooted(): void
    {
        $this->registerCallbackRoutes();
        $this->registerOctaneReset();
    }

    /**
     * Mount the callback route, but only when it was asked for.
     *
     * An unauthenticated POST endpoint standing in every application that never
     * uses one is a liability with no upside, so the route is not registered at
     * all rather than registered and guarded.
     *
     * Not `hasRoutesWhen()`: the group needs a config-driven prefix, middleware
     * stack AND rate limiter around it, which needs an explicit group.
     */
    private function registerCallbackRoutes(): void
    {
        $config = $this->app->make(PythonConfig::class);

        if (! $config->bool('callbacks.enabled', false)) {
            return;
        }

        $middleware = $config->stringList('callbacks.middleware');
        $rateLimit = $config->string('callbacks.rate_limit', '60,1');

        if ($rateLimit !== '') {
            $middleware[] = 'throttle:' . $rateLimit;
        }

        $middleware[] = VerifyPythonSignature::class;

        Route::group([
            'prefix' => $config->string('callbacks.prefix', 'api/python'),
            'middleware' => $middleware,
        ], function (): void {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/callbacks.php');
        });
    }

    /**
     * Where scripts live. Defaults to `base_path('python')`, the conventional
     * home for a Python sidecar in a Laravel repo.
     */
    private function processRoot(PythonConfig $config): string
    {
        $configured = $config->stringOrNull('process.root');

        return $configured !== null
            ? rtrim($configured, '/')
            : rtrim($this->app->basePath('python'), '/');
    }

    /**
     * Clear memoised transport state at both Octane boundaries.
     *
     * Listened for by event *name*, so there is no `class_exists` probe and no
     * dependency on Octane being installed. Without this a `Python::fake()` in
     * one request leaks into the next.
     */
    private function registerOctaneReset(): void
    {
        $events = $this->app->make(Dispatcher::class);

        foreach (['Laravel\Octane\Events\RequestReceived', 'Laravel\Octane\Events\RequestTerminated'] as $event) {
            $events->listen($event, function (): void {
                if ($this->app->resolved(PythonBridgeManager::class)) {
                    $this->app->make(PythonBridgeManager::class)->forgetTransports();
                }
            });
        }
    }
}
