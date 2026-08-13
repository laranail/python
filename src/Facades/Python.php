<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Facades;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Facade;
use Simtabi\Laranail\Python\Bridge\PythonBridgeManager;
use Simtabi\Laranail\Python\Contracts\PythonHttpClient;
use Simtabi\Laranail\Python\Contracts\PythonProcessRunner;
use Simtabi\Laranail\Python\Http\HealthReport;
use Simtabi\Laranail\Python\Testing\PythonFake;
use Simtabi\Laranail\Python\ValueObjects\PythonCall;
use Simtabi\Laranail\Python\ValueObjects\PythonResult;

/**
 * @method static PythonHttpClient http()
 * @method static PythonProcessRunner process()
 * @method static PendingRequest service(string $name)
 * @method static PendingRequest fastapi()
 * @method static PendingRequest flask()
 * @method static bool health(string $name)
 * @method static array<string, HealthReport> healthAll()
 * @method static PythonResult run(string $target, array $payload = [], ?int $timeout = null)
 * @method static PythonResult call(PythonCall $call)
 * @method static PythonFake fake(array|callable $responses = [])
 * @method static bool isFaked()
 * @method static PythonBridgeManager assertSent(?callable $matching = null)
 * @method static PythonBridgeManager assertSentTo(string $target)
 * @method static PythonBridgeManager assertSentTimes(string $target, int $expected)
 * @method static PythonBridgeManager assertNothingSent()
 * @method static void forgetTransports()
 *
 * @see PythonBridgeManager
 */
final class Python extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PythonBridgeManager::class;
    }
}
