<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Bridge\Transports;

use Illuminate\Http\Client\ConnectionException;
use Simtabi\Laranail\Python\Contracts\PythonHttpClient;
use Simtabi\Laranail\Python\Contracts\PythonTransport;
use Simtabi\Laranail\Python\Enums\ErrorCode;
use Simtabi\Laranail\Python\Enums\Transport;
use Simtabi\Laranail\Python\Exceptions\UnknownServiceException;
use Simtabi\Laranail\Python\Support\PythonConfig;
use Simtabi\Laranail\Python\Support\Redactor;
use Simtabi\Laranail\Python\ValueObjects\PythonCall;
use Simtabi\Laranail\Python\ValueObjects\PythonResult;

/**
 * Serves a call over HTTP, flattening the response into the narrow result.
 *
 * A non-2xx is a `PythonResult` with `HttpError`, not an exception. Callers of
 * `run()` are usually deciding what to do next rather than aborting, and a
 * transport that throws on a 422 forces a try/catch around every call for the
 * one case the service is most likely to return.
 */
final readonly class HttpTransport implements PythonTransport
{
    public function __construct(
        private PythonHttpClient $client,
        private PythonConfig $config,
    ) {}

    public function call(PythonCall $call): PythonResult
    {
        $service = $call->service();
        $definition = $this->client->definition($service);
        $endpoint = $call->resolvedEndpoint() ?? '/';

        $request = $this->client->service($service);

        if ($call->timeout !== null) {
            $request = $request->timeout($call->timeout);
        }

        $redactor = (new Redactor)->rememberAll($definition->auth->secrets());

        try {
            /** @var array<string, mixed> $query */
            $query = $call->payload;

            $response = strtoupper($call->method) === 'GET'
                ? $request->get($endpoint, $query)
                : $request->send($call->method, $endpoint, ['json' => $call->payload]);
        } catch (ConnectionException $e) {
            return PythonResult::failure(
                $this->isTimeout($e) ? ErrorCode::Timeout : ErrorCode::Unreachable,
                $redactor->tail($e->getMessage(), 300),
                Transport::Http,
            );
        }

        $body = $response->body();
        $limit = $this->config->int('defaults.max_response_bytes', 8_388_608);

        if ($limit > 0 && strlen($body) > $limit) {
            return PythonResult::failure(
                ErrorCode::ResponseTooLarge,
                'The response is ' . strlen($body) . " bytes, over the {$limit}-byte limit.",
                Transport::Http,
            );
        }

        $data = is_array($response->json()) ? $response->json() : [];

        if (! $response->successful()) {
            return new PythonResult(
                ok: false,
                data: $data,
                error: ErrorCode::HttpError,
                message: $redactor->tail("HTTP {$response->status()} from [{$service}{$endpoint}]", 300),
                status: $response->status(),
                via: Transport::Http,
            );
        }

        return new PythonResult(
            ok: true,
            data: $data,
            status: $response->status(),
            via: Transport::Http,
        );
    }

    public function supports(string $target): bool
    {
        try {
            $this->client->definition(
                str_contains($target, ':') ? (strstr($target, ':', true) ?: $target) : $target,
            );

            return true;
        } catch (UnknownServiceException) {
            return false;
        }
    }

    /**
     * Laravel folds a connect timeout and a read timeout into one exception
     * type, so the message is the only signal available.
     */
    private function isTimeout(ConnectionException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'timed out')
            || str_contains(strtolower($e->getMessage()), 'timeout');
    }
}
