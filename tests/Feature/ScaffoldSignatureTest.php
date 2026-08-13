<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Tests\Feature;

use Simtabi\Laranail\Python\Contracts\CallbackVerifier;
use Simtabi\Laranail\Python\Tests\TestCase;

/**
 * The scaffold's signing must agree with the verifier, or the scaffold is a lie
 * and every consumer discovers it the first time a callback fires.
 *
 * This reproduces the stub's algorithm in PHP rather than running Python, so it
 * holds in CI without an interpreter. The line under test is the string being
 * signed — "{timestamp}.{raw_body}" — which is the part that silently differs
 * when someone re-encodes before signing.
 */
final class ScaffoldSignatureTest extends TestCase
{
    private const string SECRET = 'scaffold-secret-value-x';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('laranail.python.callbacks.secrets', [self::SECRET]);
    }

    public function test_the_stub_signs_the_string_the_verifier_expects(): void
    {
        $stub = file_get_contents(__DIR__ . '/../../stubs/fastapi/main.py.stub');

        self::assertIsString($stub);

        // The stub must sign timestamp + "." + raw body, in that order, over
        // the bytes it sends.
        self::assertStringContainsString('f"{timestamp}.".encode() + body', $stub);
        self::assertStringContainsString('hashlib.sha256', $stub);
        self::assertStringContainsString('X-Laranail-Signature', $stub);
        self::assertStringContainsString('sha256={signature}', $stub);
    }

    public function test_a_signature_built_the_stub_way_verifies(): void
    {
        $verifier = $this->app->make(CallbackVerifier::class);

        // Exactly what the stub does: serialise once, sign those bytes.
        $body = json_encode(['task_id' => 't1', 'status' => 'succeeded'], JSON_THROW_ON_ERROR);
        $timestamp = time();

        $stubSignature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);

        self::assertSame($stubSignature, $verifier->sign($body, $timestamp));
    }

    public function test_the_stub_declares_the_health_contract_the_config_expects(): void
    {
        $stub = (string) file_get_contents(__DIR__ . '/../../stubs/fastapi/main.py.stub');

        self::assertStringContainsString('{"status": "healthy"}', $stub);
        self::assertSame('status', config('laranail.python.services.fastapi.health_key'));
        self::assertSame('healthy', config('laranail.python.services.fastapi.healthy_value'));
    }
}
