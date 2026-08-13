<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Simtabi\Laranail\Python\Callbacks\CallbackEnvelope;
use Simtabi\Laranail\Python\Contracts\TaskStore;
use Simtabi\Laranail\Python\Enums\TaskStatus;
use Simtabi\Laranail\Python\Events\PythonCallbackReceived;
use Simtabi\Laranail\Python\Tasks\TaskHandle;
use Simtabi\Laranail\Python\ValueObjects\PythonResult;

/**
 * Receives a verified callback and records the task's outcome.
 *
 * By the time this runs the middleware has already proved the delivery is
 * authentic, inside the timestamp window, and not a replay — so there is
 * nothing left to check here, and nothing that can be forgotten.
 */
final readonly class CallbackController
{
    public function __construct(
        private TaskStore $tasks,
        private Dispatcher $events,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $envelope = $request->attributes->get('python_callback');

        if (! $envelope instanceof CallbackEnvelope) {
            // Unreachable through the registered route; a 401 rather than a 500
            // if someone wires the controller up without the middleware.
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($envelope->taskId !== null) {
            $handle = $this->tasks->find($envelope->taskId);

            if ($handle instanceof TaskHandle) {
                $result = $envelope->status->isFinished()
                    ? new PythonResult(
                        ok: $envelope->status === TaskStatus::Succeeded,
                        data: $envelope->payload,
                    )
                    : null;

                $this->tasks->put($handle->withStatus($envelope->status), $result);
            }
        }

        $this->events->dispatch(new PythonCallbackReceived($envelope));

        return response()->json(['received' => true]);
    }
}
