<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Tests\Feature;

use Simtabi\Laranail\Python\Tests\TestCase;

/**
 * An unauthenticated POST endpoint standing in every application that never
 * uses one is a liability with no upside, so the route is not registered at
 * all rather than registered and guarded.
 */
final class CallbacksDisabledTest extends TestCase
{
    public function test_the_callback_route_is_not_registered_by_default(): void
    {
        self::assertFalse(config('laranail.python.callbacks.enabled'));

        $matching = collect($this->app['router']->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_contains($route->uri(), 'python/callbacks'));

        self::assertCount(0, $matching, 'The callback route exists without being enabled.');
    }

    public function test_posting_to_the_callback_path_is_a_404(): void
    {
        $this->postJson('api/python/callbacks', ['task_id' => 't1'])->assertNotFound();
    }
}
