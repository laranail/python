<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Simtabi\Laranail\Python\Http\Controllers\CallbackController;

/*
 * Registered only when laranail.python.callbacks.enabled is true. The group's
 * prefix, middleware and rate limit are applied by the service provider, which
 * is why they are not repeated here.
 */

Route::post('/callbacks', CallbackController::class)->name('laranail.python.callback');
