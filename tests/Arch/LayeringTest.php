<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

arch('the package never reaches a shell')
    ->expect(['shell_exec', 'exec', 'passthru', 'system', 'proc_open', 'popen'])
    ->not->toBeUsed();

arch('nothing is left debugging')
    ->expect(['dd', 'dump', 'var_dump', 'ray'])
    ->not->toBeUsed();

arch('env is read in the config file and nowhere else')
    ->expect('Simtabi\Laranail\Python')
    ->not->toUse('env');

arch('contracts are interfaces')
    ->expect('Simtabi\Laranail\Python\Contracts')
    ->toBeInterfaces();

arch('enums are string backed')
    ->expect('Simtabi\Laranail\Python\Enums')
    ->toBeStringBackedEnums();

arch('value objects stay immutable')
    ->expect('Simtabi\Laranail\Python\ValueObjects')
    ->toBeReadonly();

arch('phpunit stays out of production code')
    ->expect('Simtabi\Laranail\Python')
    ->not->toUse(Assert::class)
    ->ignoring('Simtabi\Laranail\Python\Testing');

arch('transports and resolvers take their dependencies by injection')
    ->expect([
        'Simtabi\Laranail\Python\Http',
        'Simtabi\Laranail\Python\Process',
        'Simtabi\Laranail\Python\Bridge',
    ])
    ->not->toUse(['app', 'config', 'request', 'session', 'resolve']);

arch('strict types everywhere')
    ->expect('Simtabi\Laranail\Python')
    ->toUseStrictTypes();
