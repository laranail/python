<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Http\Middleware;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Simtabi\Laranail\Python\Contracts\CallbackVerifier;
use Simtabi\Laranail\Python\Events\PythonCallbackRejected;
use Simtabi\Laranail\Python\Exceptions\CallbackVerificationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses any inbound callback that does not verify.
 *
 * The response is a bare 401 with no body detail on every failure path.
 * Reporting *which* check failed — bad signature, stale timestamp, replayed id
 * — tells an attacker which knob to turn next. The reason goes out on
 * {@see PythonCallbackRejected} instead, where the application can alert on it.
 */
final readonly class VerifyPythonSignature
{
    public function __construct(
        private CallbackVerifier $verifier,
        private Dispatcher $events,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $envelope = $this->verifier->verify($request);
        } catch (CallbackVerificationException $e) {
            $this->events->dispatch(new PythonCallbackRejected(
                reason: $e->reason,
                deliveryId: $request->header('X-Laranail-Id'),
                ip: $request->ip(),
            ));

            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $request->attributes->set('python_callback', $envelope);

        return $next($request);
    }
}
