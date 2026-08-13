<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Tests\Feature\Security;

use DateTimeImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Psr\Clock\ClockInterface;
use Simtabi\Laranail\Python\Contracts\CallbackVerifier;
use Simtabi\Laranail\Python\Enums\RejectionReason;
use Simtabi\Laranail\Python\Events\PythonCallbackReceived;
use Simtabi\Laranail\Python\Events\PythonCallbackRejected;
use Simtabi\Laranail\Python\Tests\TestCase;

/**
 * The callback surface is the one unauthenticated entry point this package can
 * open, so every refusal path is asserted rather than assumed.
 */
final class CallbackReplayTest extends TestCase
{
    private const string SECRET = 'callback-secret-value-for-tests';

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-13 12:00:00'));
        $this->app->instance(ClockInterface::class, $this->clock);
        $this->app->forgetInstance(CallbackVerifier::class);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('laranail.python.callbacks.enabled', true);
        $app['config']->set('laranail.python.callbacks.secrets', [self::SECRET]);
        $app['config']->set('laranail.python.callbacks.tolerance', 300);
        $app['config']->set('cache.default', 'array');
    }

    /**
     * @param array<string, string> $overrides
     */
    private function deliver(array $payload, array $overrides = [], ?int $at = null): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = $at ?? $this->clock->now()->getTimestamp();

        $headers = [
            'X-Laranail-Timestamp' => (string) $timestamp,
            'X-Laranail-Signature' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET),
            'X-Laranail-Id' => 'delivery-1',
            'Content-Type' => 'application/json',
            ...$overrides,
        ];

        return $this->call('POST', 'api/python/callbacks', [], [], [], $this->serverHeaders($headers), $body);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function serverHeaders(array $headers): array
    {
        $server = [];

        foreach ($headers as $key => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
        }

        return $server;
    }

    // -----------------------------------------------------------------

    public function test_a_correctly_signed_delivery_is_accepted(): void
    {
        Event::fake([PythonCallbackReceived::class]);

        $this->deliver(['task_id' => 't1', 'status' => 'succeeded'])->assertOk();

        Event::assertDispatched(PythonCallbackReceived::class);
    }

    public function test_the_same_delivery_twice_is_refused(): void
    {
        // The timestamp window alone does not stop this: the second request is
        // byte-identical and its signature is still perfectly valid.
        $this->deliver(['task_id' => 't1'])->assertOk();

        Event::fake([PythonCallbackRejected::class]);

        $this->deliver(['task_id' => 't1'])->assertUnauthorized();

        Event::assertDispatched(
            PythonCallbackRejected::class,
            fn (PythonCallbackRejected $e): bool => $e->reason === RejectionReason::Replayed,
        );
    }

    public function test_a_replay_without_an_id_header_is_still_refused(): void
    {
        // Falls back to keying on the signature, which is unique per
        // (body, timestamp, secret).
        $this->deliver(['task_id' => 't2'], ['X-Laranail-Id' => ''])->assertOk();
        $this->deliver(['task_id' => 't2'], ['X-Laranail-Id' => ''])->assertUnauthorized();
    }

    public function test_a_forged_signature_is_refused(): void
    {
        Event::fake([PythonCallbackRejected::class]);

        $this->deliver(['task_id' => 't1'], ['X-Laranail-Signature' => 'sha256=deadbeef'])
            ->assertUnauthorized();

        Event::assertDispatched(
            PythonCallbackRejected::class,
            fn (PythonCallbackRejected $e): bool => $e->reason === RejectionReason::SignatureMismatch,
        );
    }

    public function test_a_signature_over_a_reencoded_body_is_refused(): void
    {
        // Signing a re-encoded array rather than the raw bytes is the mistake
        // that makes a verifier depend on two JSON encoders agreeing.
        $timestamp = $this->clock->now()->getTimestamp();
        // Whitespace is the difference a re-encode erases. PHP preserves key
        // order, so reordering alone would not prove anything.
        $raw = '{"a": 1, "b": 2}';
        $reencoded = json_encode(json_decode($raw, true), JSON_THROW_ON_ERROR);

        $response = $this->call('POST', 'api/python/callbacks', [], [], [], $this->serverHeaders([
            'X-Laranail-Timestamp' => (string) $timestamp,
            'X-Laranail-Signature' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $reencoded, self::SECRET),
            'X-Laranail-Id' => 'reordered',
        ]), $raw);

        $response->assertUnauthorized();
    }

    public function test_a_stale_timestamp_is_refused(): void
    {
        Event::fake([PythonCallbackRejected::class]);

        $this->deliver(['task_id' => 't1'], [], $this->clock->now()->getTimestamp() - 3600)
            ->assertUnauthorized();

        Event::assertDispatched(
            PythonCallbackRejected::class,
            fn (PythonCallbackRejected $e): bool => $e->reason === RejectionReason::TimestampOutOfRange,
        );
    }

    public function test_a_future_timestamp_is_refused(): void
    {
        $this->deliver(['task_id' => 't1'], [], $this->clock->now()->getTimestamp() + 3600)
            ->assertUnauthorized();
    }

    public function test_a_missing_signature_is_refused(): void
    {
        $this->deliver(['task_id' => 't1'], ['X-Laranail-Signature' => ''])->assertUnauthorized();
    }

    public function test_a_missing_timestamp_is_refused(): void
    {
        $this->deliver(['task_id' => 't1'], ['X-Laranail-Timestamp' => ''])->assertUnauthorized();
    }

    public function test_the_response_never_says_which_check_failed(): void
    {
        // Naming the failing check tells an attacker which knob to turn next.
        $response = $this->deliver(['task_id' => 't1'], ['X-Laranail-Signature' => 'sha256=wrong']);

        $response->assertUnauthorized();
        self::assertSame(['message' => 'Unauthorized'], $response->json());
    }

    public function test_an_oversized_body_is_refused_before_it_is_parsed(): void
    {
        config()->set('laranail.python.callbacks.max_body_bytes', 32);

        $this->deliver(['padding' => str_repeat('x', 500)])->assertUnauthorized();
    }

    public function test_a_second_secret_still_verifies_during_rotation(): void
    {
        config()->set('laranail.python.callbacks.secrets', ['the-new-secret-value', self::SECRET]);
        $this->app->forgetInstance(CallbackVerifier::class);

        // Signed with the OLD secret, which is now second in the list.
        $this->deliver(['task_id' => 'rotating'])->assertOk();
    }
}

/**
 * A clock that does not move, so the tolerance window can be tested without
 * sleeping for five minutes.
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function travel(int $seconds): void
    {
        $this->now = $this->now->modify("{$seconds} seconds");
    }
}
