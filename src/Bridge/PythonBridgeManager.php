<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Bridge;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Simtabi\Laranail\Python\Bridge\Transports\HttpTransport;
use Simtabi\Laranail\Python\Contracts\PythonHttpClient;
use Simtabi\Laranail\Python\Contracts\PythonProcessRunner;
use Simtabi\Laranail\Python\Contracts\TaskStore;
use Simtabi\Laranail\Python\Enums\ErrorCode;
use Simtabi\Laranail\Python\Enums\TaskStatus;
use Simtabi\Laranail\Python\Enums\Transport;
use Simtabi\Laranail\Python\Events\PythonCallFailed;
use Simtabi\Laranail\Python\Events\PythonCallStarted;
use Simtabi\Laranail\Python\Events\PythonCallSucceeded;
use Simtabi\Laranail\Python\Events\PythonTaskSubmitted;
use Simtabi\Laranail\Python\Exceptions\InvalidPayloadException;
use Simtabi\Laranail\Python\Exceptions\ProcessDisabledException;
use Simtabi\Laranail\Python\Exceptions\ProcessFailedException;
use Simtabi\Laranail\Python\Exceptions\PythonException;
use Simtabi\Laranail\Python\Exceptions\UnknownServiceException;
use Simtabi\Laranail\Python\Http\HealthReport;
use Simtabi\Laranail\Python\Support\PythonConfig;
use Simtabi\Laranail\Python\Tasks\TaskHandle;
use Simtabi\Laranail\Python\Testing\PythonFake;
use Simtabi\Laranail\Python\ValueObjects\ProcessCall;
use Simtabi\Laranail\Python\ValueObjects\PythonCall;
use Simtabi\Laranail\Python\ValueObjects\PythonResult;
use Throwable;

/**
 * The facade target: routes a call to the transport that can serve it, and
 * keeps both wide seams reachable.
 *
 * A target naming a registered script goes to the process runner; anything else
 * is an HTTP service. That ordering is deliberate — script names are an
 * explicit allow-list, so it is the narrower set and cannot be widened by a
 * typo in a service name.
 */
class PythonBridgeManager
{
    private ?PythonFake $fake = null;

    public function __construct(
        private readonly PythonConfig $config,
        private readonly PythonHttpClient $http,
        private readonly PythonProcessRunner $process,
        private readonly HttpTransport $httpTransport,
        private readonly TaskStore $tasks,
        private readonly Dispatcher $events,
    ) {}

    // --- Wide seams ---------------------------------------------------------

    public function http(): PythonHttpClient
    {
        return $this->http;
    }

    public function process(): PythonProcessRunner
    {
        return $this->process;
    }

    // --- HTTP conveniences --------------------------------------------------

    public function service(string $name): PendingRequest
    {
        return $this->http->service($name);
    }

    public function fastapi(): PendingRequest
    {
        return $this->http->fastapi();
    }

    public function flask(): PendingRequest
    {
        return $this->http->flask();
    }

    public function health(string $name): bool
    {
        return $this->fake?->isFaked() === true || $this->http->health($name);
    }

    /**
     * @return array<string, HealthReport>
     */
    public function healthAll(): array
    {
        if ($this->fake instanceof PythonFake) {
            return $this->fake->healthAll($this->http->names());
        }

        return $this->http->healthAll();
    }

    // --- The narrow contract ------------------------------------------------

    /**
     * Run a target with a payload, whichever transport serves it.
     *
     * @param array<array-key, mixed> $payload
     */
    public function run(string $target, array $payload = [], ?int $timeout = null): PythonResult
    {
        return $this->call(new PythonCall(
            target: $target,
            payload: $payload,
            timeout: $timeout,
        ));
    }

    public function call(PythonCall $call): PythonResult
    {
        $started = microtime(true);

        $this->events->dispatch(new PythonCallStarted($call));

        try {
            $result = $this->dispatchCall($call);
        } catch (PythonException $e) {
            $result = PythonResult::failure(
                $this->errorFor($e),
                $e->getMessage(),
                $this->transportFor($call),
            );
        } catch (Throwable $e) {
            $result = PythonResult::failure(
                ErrorCode::Unreachable,
                $e->getMessage(),
                $this->transportFor($call),
            );
        }

        $result = $result
            ->withDuration((microtime(true) - $started) * 1000)
            ->withCorrelationId($call->correlationId);

        $this->events->dispatch($result->ok
            ? new PythonCallSucceeded($call, $result)
            : new PythonCallFailed($call, $result));

        return $result;
    }

    // --- Async tasks --------------------------------------------------------

    /**
     * Hand work over and stop waiting for it.
     *
     * Inference is slow; a synchronous HTTP call to something that takes four
     * minutes is the wrong shape whatever the timeout says. The service answers
     * with a task id, and reports completion either by being polled or by
     * calling back.
     *
     * @param array<array-key, mixed> $payload
     */
    public function submit(string $target, array $payload = [], ?string $callbackUrl = null): TaskHandle
    {
        $result = $this->call(new PythonCall(
            target: $target,
            payload: $callbackUrl === null ? $payload : [...$payload, 'callback_url' => $callbackUrl],
            endpoint: '/submit',
        ));

        $id = $result->get('task_id');

        $handle = new TaskHandle(
            id: is_scalar($id) ? (string) $id : bin2hex(random_bytes(8)),
            target: $target,
            status: $result->ok ? TaskStatus::Running : TaskStatus::Failed,
            pollUrl: $this->pollUrlFor(is_scalar($id) ? (string) $id : ''),
            submittedAt: time(),
        );

        $this->tasks->put($handle, $result->ok ? null : $result);
        $this->events->dispatch(new PythonTaskSubmitted($handle));

        return $handle;
    }

    /**
     * Refresh a handle.
     *
     * A callback may already have written the outcome, so the store is checked
     * first — polling a service that has since called back would otherwise
     * report stale state.
     */
    public function status(TaskHandle $handle): TaskHandle
    {
        $stored = $this->tasks->find($handle->id);

        if ($stored?->isFinished() === true) {
            return $stored;
        }

        $result = $this->call(new PythonCall(
            target: $handle->target,
            method: 'GET',
            endpoint: $this->pollPathFor($handle->id),
        ));

        $raw = $result->get($this->config->string('tasks.status_key', 'status'));

        $status = TaskStatus::tryFrom(is_scalar($raw) ? (string) $raw : '')
            ?? ($stored instanceof TaskHandle ? $stored->status : TaskStatus::Running);

        $refreshed = $handle->withStatus($status);

        $this->tasks->put(
            $refreshed,
            $status->isFinished() ? $result : null,
        );

        return $refreshed;
    }

    /** The result of a finished task, when there is one. */
    public function result(TaskHandle $handle): ?PythonResult
    {
        return $this->tasks->result($handle->id);
    }

    // --- Testing ------------------------------------------------------------

    /**
     * Install a fake for both transports.
     *
     * @param array<string, mixed>|callable(PythonCall): PythonResult $responses
     */
    public function fake(array|callable $responses = []): PythonFake
    {
        return $this->fake = new PythonFake($responses);
    }

    public function isFaked(): bool
    {
        return $this->fake instanceof PythonFake;
    }

    public function assertSent(?callable $matching = null): static
    {
        $this->requireFake()->assertSent($matching);

        return $this;
    }

    public function assertSentTo(string $target): static
    {
        $this->requireFake()->assertSentTo($target);

        return $this;
    }

    public function assertSentTimes(string $target, int $expected): static
    {
        $this->requireFake()->assertSentTimes($target, $expected);

        return $this;
    }

    public function assertNothingSent(): static
    {
        $this->requireFake()->assertNothingSent();

        return $this;
    }

    /** Drop memoised transport state — Octane boundaries and tests. */
    public function forgetTransports(): void
    {
        $this->fake = null;
    }

    // --- Internals ----------------------------------------------------------

    private function dispatchCall(PythonCall $call): PythonResult
    {
        if ($this->fake instanceof PythonFake) {
            return $this->fake->handle($call);
        }

        if ($this->isScript($call->target)) {
            return $this->process->run(new ProcessCall(
                script: $call->target,
                payload: $call->payload,
                args: $call->args,
                timeout: $call->timeout,
            ));
        }

        return $this->httpTransport->call($call);
    }

    private function isScript(string $target): bool
    {
        return array_key_exists($target, $this->config->array('process.scripts'));
    }

    private function transportFor(PythonCall $call): Transport
    {
        if ($this->fake instanceof PythonFake) {
            return Transport::Fake;
        }

        return $this->isScript($call->target) ? Transport::Process : Transport::Http;
    }

    private function pollPathFor(string $id): string
    {
        return str_replace('{id}', rawurlencode($id), $this->config->string('tasks.poll_path', '/tasks/{id}'));
    }

    private function pollUrlFor(string $id): ?string
    {
        return $id === '' ? null : $this->pollPathFor($id);
    }

    private function errorFor(PythonException $e): ErrorCode
    {
        return match (true) {
            $e instanceof UnknownServiceException => ErrorCode::UnknownService,
            $e instanceof ProcessDisabledException => ErrorCode::Disabled,
            $e instanceof ProcessFailedException => ErrorCode::ProcessFailed,
            $e instanceof InvalidPayloadException => ErrorCode::InvalidPayload,
            default => ErrorCode::Unreachable,
        };
    }

    private function requireFake(): PythonFake
    {
        if (! $this->fake instanceof PythonFake) {
            throw new PythonException(
                'No fake is installed. Call Python::fake() before asserting.',
                code: 3600,
            );
        }

        return $this->fake;
    }
}
