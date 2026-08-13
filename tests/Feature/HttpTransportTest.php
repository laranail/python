<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Simtabi\Laranail\Python\Contracts\PythonHttpClient;
use Simtabi\Laranail\Python\Enums\ErrorCode;
use Simtabi\Laranail\Python\Exceptions\MissingBaseUrlException;
use Simtabi\Laranail\Python\Exceptions\UnknownServiceException;
use Simtabi\Laranail\Python\Facades\Python;
use Simtabi\Laranail\Python\Tests\TestCase;

final class HttpTransportTest extends TestCase
{
    private function client(): PythonHttpClient
    {
        return $this->app->make(PythonHttpClient::class);
    }

    public function test_it_lists_configured_services(): void
    {
        self::assertSame(['fastapi', 'flask'], $this->client()->names());
    }

    public function test_an_unknown_service_names_the_ones_that_exist(): void
    {
        try {
            $this->client()->service('nope');
            self::fail('Expected an UnknownServiceException.');
        } catch (UnknownServiceException $e) {
            self::assertStringContainsString('fastapi', $e->getMessage());
            self::assertSame(3001, $e->getCode());
        }
    }

    public function test_a_service_without_a_base_url_is_refused(): void
    {
        config()->set('laranail.python.services.bare', ['timeout' => 5]);

        $this->expectException(MissingBaseUrlException::class);

        $this->client()->service('bare');
    }

    public function test_the_health_contract_is_configurable(): void
    {
        Http::fake(['*/health' => Http::response(['state' => 'up'], 200)]);

        config()->set('laranail.python.services.fastapi.health_key', 'state');
        config()->set('laranail.python.services.fastapi.healthy_value', 'up');

        self::assertTrue($this->client()->health('fastapi'));
    }

    public function test_a_non_matching_health_value_is_unhealthy(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'degraded'], 200)]);

        self::assertFalse($this->client()->health('fastapi'));
    }

    public function test_a_non_string_health_value_still_matches(): void
    {
        Http::fake(['*/health' => Http::response(['status' => true], 200)]);

        config()->set('laranail.python.services.fastapi.healthy_value', '1');

        self::assertTrue($this->client()->health('fastapi'));
    }

    public function test_health_all_probes_every_service(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'healthy'], 200)]);

        $reports = $this->client()->healthAll();

        self::assertSame(['fastapi', 'flask'], array_keys($reports));
        self::assertTrue($reports['fastapi']->healthy);
        self::assertNotNull($reports['fastapi']->roundTripMs);
    }

    public function test_a_bearer_token_is_sent(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        config()->set('laranail.python.services.fastapi.auth', [
            'scheme' => 'bearer',
            'token' => 'secret-token-value',
        ]);

        $this->client()->service('fastapi')->get('/anything');

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer secret-token-value'));
    }

    public function test_an_api_key_header_is_sent(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        config()->set('laranail.python.services.fastapi.auth', [
            'scheme' => 'api_key',
            'token' => 'key-value-here',
            'header' => 'X-Custom-Key',
        ]);

        $this->client()->service('fastapi')->get('/anything');

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Custom-Key', 'key-value-here'));
    }

    public function test_an_auth_scheme_without_its_credential_is_reported_incomplete(): void
    {
        config()->set('laranail.python.services.fastapi.auth', ['scheme' => 'bearer']);

        self::assertFalse($this->client()->definition('fastapi')->auth->isComplete());
    }

    public function test_a_non_2xx_becomes_a_result_not_an_exception(): void
    {
        Http::fake(['*' => Http::response(['detail' => 'nope'], 422)]);

        $result = Python::run('fastapi:predict', ['x' => 1]);

        self::assertFalse($result->ok);
        self::assertSame(ErrorCode::HttpError, $result->error);
        self::assertSame(422, $result->status);
        self::assertSame('nope', $result->get('detail'));
    }

    public function test_a_successful_call_carries_the_decoded_body(): void
    {
        Http::fake(['*' => Http::response(['vector' => [1, 2, 3]], 200)]);

        $result = Python::run('fastapi:embed', ['text' => 'hi']);

        self::assertTrue($result->ok);
        self::assertSame([1, 2, 3], $result->get('vector'));
        self::assertGreaterThanOrEqual(0.0, $result->durationMs);
    }

    public function test_an_unknown_target_fails_without_throwing(): void
    {
        $result = Python::run('not-a-service:x');

        self::assertFalse($result->ok);
        self::assertSame(ErrorCode::UnknownService, $result->error);
    }

    public function test_tls_mode_is_reported_for_the_doctor(): void
    {
        config()->set('laranail.python.services.fastapi.verify_ssl', false);
        self::assertStringContainsString('insecure', $this->client()->definition('fastapi')->tlsMode());

        config()->set('laranail.python.services.fastapi.verify_ssl', true);
        self::assertStringContainsString('system CA', $this->client()->definition('fastapi')->tlsMode());
    }

    public function test_a_base_url_with_credentials_is_flagged(): void
    {
        config()->set('laranail.python.services.fastapi.base_url', 'https://user:pass@svc.internal');

        self::assertStringContainsString(
            'userinfo',
            (string) $this->client()->definition('fastapi')->baseUrlProblem(),
        );
    }

    public function test_a_client_error_is_not_retried(): void
    {
        // Laravel's retry() throws on every non-2xx once tries > 1, so the
        // naive retry(3, 100) this was extracted from hammered a service three
        // times with a request that could never succeed.
        Http::fake(['*' => Http::response(['detail' => 'nope'], 422)]);

        Python::run('fastapi:predict');

        Http::assertSentCount(1);
    }

    public function test_a_server_error_is_retried(): void
    {
        Http::fake(['*' => Http::response(['detail' => 'boom'], 503)]);

        config()->set('laranail.python.services.fastapi.retry_times', 3);
        config()->set('laranail.python.services.fastapi.retry_sleep_ms', 1);

        $result = Python::run('fastapi:predict');

        self::assertFalse($result->ok);
        self::assertSame(503, $result->status);
        Http::assertSentCount(3);
    }

    public function test_a_non_http_scheme_is_flagged(): void
    {
        config()->set('laranail.python.services.fastapi.base_url', 'file:///etc/passwd');

        self::assertStringContainsString(
            'http or https',
            (string) $this->client()->definition('fastapi')->baseUrlProblem(),
        );
    }
}
