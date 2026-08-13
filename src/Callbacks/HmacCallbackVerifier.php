<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Callbacks;

use Illuminate\Http\Request;
use Psr\Clock\ClockInterface;
use Simtabi\Laranail\Python\Contracts\CallbackVerifier;
use Simtabi\Laranail\Python\Contracts\ReplayGuard;
use Simtabi\Laranail\Python\Enums\RejectionReason;
use Simtabi\Laranail\Python\Enums\TaskStatus;
use Simtabi\Laranail\Python\Exceptions\CallbackVerificationException;
use Simtabi\Laranail\Python\Support\Json;
use Simtabi\Laranail\Python\Support\PythonConfig;

/**
 * HMAC verification for inbound callbacks.
 *
 * ## What is signed
 *
 * `"{timestamp}.{rawBody}"`, with the **raw** body exactly as it arrived.
 * Never a re-encoded array: re-encoding changes key order and whitespace, so
 * the signature would depend on PHP's JSON encoder agreeing byte-for-byte with
 * Python's. Worse, it invites a "canonicalise, then sign" scheme, which is
 * where essentially every webhook signature CVE lives.
 *
 * ## Two gates, both needed
 *
 * The timestamp window bounds how long a captured request stays useful. It does
 * **not** stop replay — anyone holding the request can send it again inside the
 * window with a perfectly valid signature. The delivery-id claim is what closes
 * that, and it is the step most implementations skip.
 *
 * ## Rotation
 *
 * `secrets` is a list. Verification tries each in turn; signing always uses the
 * first. Rotate by prepending the new secret, deploying, then dropping the tail
 * — at no point is there a window where valid deliveries are refused.
 */
final readonly class HmacCallbackVerifier implements CallbackVerifier
{
    public function __construct(
        private PythonConfig $config,
        private ReplayGuard $replays,
        private ClockInterface $clock,
    ) {}

    public function verify(Request $request): CallbackEnvelope
    {
        $secrets = $this->config->stringList('callbacks.secrets');

        if ($secrets === []) {
            throw CallbackVerificationException::because(RejectionReason::NoSecretConfigured);
        }

        $body = $request->getContent();
        $maxBytes = $this->config->int('callbacks.max_body_bytes', 1_048_576);

        // Checked before anything parses the body, so an oversized delivery
        // costs a strlen() rather than a decode.
        if ($maxBytes > 0 && strlen($body) > $maxBytes) {
            throw CallbackVerificationException::because(RejectionReason::BodyTooLarge);
        }

        $signature = $request->header($this->config->string('callbacks.signature_header', 'X-Laranail-Signature'));
        $timestamp = $request->header($this->config->string('callbacks.timestamp_header', 'X-Laranail-Timestamp'));

        if (! is_string($signature) || trim($signature) === '') {
            throw CallbackVerificationException::because(RejectionReason::MissingSignature);
        }

        if (! is_string($timestamp) || ! is_numeric($timestamp)) {
            throw CallbackVerificationException::because(RejectionReason::MissingTimestamp);
        }

        $timestamp = (int) $timestamp;
        $tolerance = $this->config->int('callbacks.tolerance', 300);

        if ($tolerance > 0 && abs($this->clock->now()->getTimestamp() - $timestamp) > $tolerance) {
            throw CallbackVerificationException::because(RejectionReason::TimestampOutOfRange);
        }

        if (! $this->signatureMatches($signature, $body, $timestamp, $secrets)) {
            throw CallbackVerificationException::because(RejectionReason::SignatureMismatch);
        }

        $id = $this->deliveryId($request, $signature);

        // Twice the tolerance, so an id cannot age out while its timestamp is
        // still inside the window.
        if (! $this->replays->claim($id, max(60, $tolerance * 2))) {
            throw CallbackVerificationException::because(RejectionReason::Replayed);
        }

        return $this->envelope($id, $timestamp, $body);
    }

    public function sign(string $body, int $timestamp): string
    {
        $secrets = $this->config->stringList('callbacks.secrets');
        $secret = $secrets[0] ?? '';

        return 'sha256=' . hash_hmac($this->algo(), $timestamp . '.' . $body, $secret);
    }

    /**
     * @param list<string> $secrets
     */
    private function signatureMatches(string $signature, string $body, int $timestamp, array $secrets): bool
    {
        $signed = $timestamp . '.' . $body;

        foreach ($secrets as $secret) {
            $expected = 'sha256=' . hash_hmac($this->algo(), $signed, $secret);

            // Constant time: a short-circuiting comparison leaks the correct
            // prefix one byte at a time.
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The id a replay claim is keyed on.
     *
     * A caller-supplied id header when present, and the signature otherwise —
     * which is unique per (body, timestamp, secret) and therefore still refuses
     * an exact replay from a service that sends no id.
     */
    private function deliveryId(Request $request, string $signature): string
    {
        $header = $request->header($this->config->string('callbacks.id_header', 'X-Laranail-Id'));

        return is_string($header) && trim($header) !== ''
            ? trim($header)
            : hash('sha256', $signature);
    }

    private function envelope(string $id, int $timestamp, string $body): CallbackEnvelope
    {
        $payload = Json::decode($body, $this->config->int('callbacks.max_body_bytes', 1_048_576));

        $taskId = $payload['task_id'] ?? null;
        $status = $payload[$this->config->string('tasks.status_key', 'status')] ?? null;

        return new CallbackEnvelope(
            id: $id,
            timestamp: $timestamp,
            payload: $payload,
            taskId: is_scalar($taskId) ? (string) $taskId : null,
            status: TaskStatus::tryFrom(is_scalar($status) ? (string) $status : '') ?? TaskStatus::Succeeded,
        );
    }

    private function algo(): string
    {
        $algo = $this->config->string('callbacks.algo', 'sha256');

        return in_array($algo, hash_hmac_algos(), true) ? $algo : 'sha256';
    }
}
