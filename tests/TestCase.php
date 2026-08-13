<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Simtabi\Laranail\Python\Facades\Python;
use Simtabi\Laranail\Python\Providers\PythonServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PythonServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Python' => Python::class];
    }
}
