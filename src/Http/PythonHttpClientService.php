<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Psr\Log\LoggerInterface;
use Simtabi\Laranail\Python\Contracts\PythonHttpClient;
use Simtabi\Laranail\Python\Enums\Transport;
use Simtabi\Laranail\Python\Exceptions\UnknownServiceException;
use Simtabi\Laranail\Python\Support\PythonConfig;
use Simtabi\Laranail\Python\Support\Redactor;
use Throwable;

/**
 * Config-driven factory of HTTP clients for named Python services.
 *
 * Every service is a config entry — base URL, timeout, retry, TLS, auth, and
 * the health contract — so adding one is configuration rather than code.
 * `fastapi()` and `flask()` are named conveniences over `service()`, kept
 * because they read well at a call site, not because either is special.
 */
final readonly class PythonHttpClientService implements PythonHttpClient
{
    public function __construct(
        private PythonConfig $config,
        private HttpFactory $http,
        private LoggerInterface $logger,
    ) {}

    public function service(string $name): PendingRequest
    {
        return $this->buildRequest($this->definition($name));
    }

    public function fastapi(): PendingRequest
    {
        return $this->service('fastapi');
    }

    public function flask(): PendingRequest
    {
        return $this->service('flask');
    }

    public function health(string $name): bool
    {
        return $this->report($name)->healthy;
    }

    public function healthAll(): array
    {
        $reports = [];

        foreach ($this->names() as $name) {
            $reports[$name] = $this->report($name);
        }

        return $reports;
    }

    public function report(string $name): HealthReport
    {
        $definition = $this->definition($name);
        $started = microtime(true);

        try {
            $response = $this->buildRequest($definition)->get($definition->healthPath);

            $elapsed = round((microtime(true) - $started) * 1000, 2);

            // Stringify both sides so a health value that arrives as a bool or
            // an int can still match a configured `healthy_value`.
            $healthy = $response->successful()
                && $this->stringify($response->json($definition->healthKey)) === $definition->healthyValue;

            return new HealthReport(
                name: $name,
                healthy: $healthy,
                roundTripMs: $elapsed,
                baseUrl: $definition->baseUrl,
                tlsMode: $definition->tlsMode(),
                error: $healthy ? null : 'HTTP ' . $response->status() . ' did not satisfy the health contract',
                via: Transport::Http,
            );
        } catch (Throwable $e) {
            $message = $this->redactorFor($definition)->tail($e->getMessage(), 300);

            $this->logger->warning('Python service health check failed', [
                'service' => $name,
                'error' => $message,
            ]);

            return new HealthReport(
                name: $name,
                healthy: false,
                roundTripMs: round((microtime(true) - $started) * 1000, 2),
                baseUrl: $definition->baseUrl,
                tlsMode: $definition->tlsMode(),
                error: $message,
                via: Transport::Http,
            );
        }
    }

    public function names(): array
    {
        $names = [];

        foreach (array_keys($this->config->array('services')) as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function definition(string $name): ServiceDefinition
    {
        $raw = $this->config->array("services.{$name}");

        if ($raw === [] && ! $this->config->has("services.{$name}")) {
            throw UnknownServiceException::for($name, $this->names());
        }

        return ServiceDefinition::fromArray(
            $name,
            $raw,
            $this->config->int('defaults.timeout', 30),
            $this->config->int('defaults.connect_timeout', 5),
        );
    }

    /**
     * Build the configured, JSON-aware client for a service.
     */
    private function buildRequest(ServiceDefinition $definition): PendingRequest
    {
        $request = $this->http
            ->baseUrl($definition->requireBaseUrl())
            ->timeout($definition->timeout)
            ->connectTimeout($definition->connectTimeout)
            ->retry(
                $definition->retryTimes,
                $definition->retrySleepMs,
                when: $this->shouldRetry(...),
                throw: false,
            )
            ->acceptJson();

        if ($definition->headers !== []) {
            $request = $request->withHeaders($definition->headers);
        }

        $request = $definition->auth->apply($request);

        return $this->applyTls($request, $definition);
    }

    /**
     * Retry a connection failure or a server error. Never a client error.
     *
     * Laravel's `retry()` throws on **every** non-2xx once `tries > 1`, so the
     * naive `retry(3, 100)` this was extracted from retried a 422 three times —
     * a validation response is never going to become a success — and then
     * raised it as an exception rather than returning it. `throw: false` keeps
     * the response, and this narrows retries to what can actually recover.
     */
    private function shouldRetry(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException && $e->response->serverError();
    }

    private function applyTls(PendingRequest $request, ServiceDefinition $definition): PendingRequest
    {
        if (! $definition->verifySsl) {
            return $request->withOptions(['verify' => false]);
        }

        if ($definition->caCert === null) {
            return $request;
        }

        if (is_file($definition->caCert)) {
            return $request->withOptions(['verify' => $definition->caCert]);
        }

        // Configured but absent. Falling through silently would look like
        // working TLS while quietly using a different trust store, so warn and
        // keep verification on against the system bundle.
        $this->logger->warning('Python service CA certificate configured but not found; using the system CA bundle', [
            'service' => $definition->name,
            'ca_cert' => $definition->caCert,
        ]);

        return $request;
    }

    /**
     * A redactor primed with everything this service would inject.
     */
    private function redactorFor(ServiceDefinition $definition): Redactor
    {
        return (new Redactor)->rememberAll($definition->auth->secrets());
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
