<?php

declare(strict_types=1);

use Simtabi\Laranail\Python\Tests\TestCase;

// Unit and Arch get no container on purpose: anything that needs one is a
// Feature test, and keeping that line sharp is what stops a "unit" test from
// quietly depending on a booted application.
uses(TestCase::class)->in('Feature');
