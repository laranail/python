# Signed callbacks

Long-running work finishes after the request that started it has gone, so it has
to report back. This is the inbound half of the bridge.

**It is off by default**, and the route is not registered at all until you turn
it on — see [architecture](../architecture.md) for why absent beats guarded.

## Enabling it

```php
// config/laranail/python.php
'callbacks' => [
    'enabled' => true,
    'prefix'  => 'api/python',
    'secrets' => [env('PYTHON_CALLBACK_SECRET')],
],
```

That registers `POST /api/python/callbacks`, behind the `api` middleware group,
a throttle, and signature verification.

## What a caller must send

| Header | Value |
|---|---|
| `X-Laranail-Signature` | `sha256=` + HMAC-SHA256 of `"{timestamp}.{rawBody}"` |
| `X-Laranail-Timestamp` | Unix seconds |
| `X-Laranail-Id` | a unique delivery id (optional but recommended) |

```python
body = json.dumps(payload, separators=(",", ":")).encode()
timestamp = str(int(time.time()))
signature = hmac.new(SECRET.encode(), f"{timestamp}.".encode() + body, hashlib.sha256).hexdigest()
```

Sign the **exact bytes you send**. Serialising once to sign and again to send is
how signatures start failing the first time a payload contains a non-ASCII
character.

`php artisan laranail::python.make-service` generates a service that already
does this, and a test asserts the stub's signing agrees with the verifier — so
the scaffold cannot drift from the thing it is scaffolding for.

## What gets refused

Every one of these is a bare `401` with no detail in the body. Which check
failed goes out on the `PythonCallbackRejected` event instead, because naming it
on the wire tells an attacker which knob to turn next.

| Reason | When |
|---|---|
| `NoSecretConfigured` | callbacks enabled, no secret — every delivery is refused |
| `MissingSignature` / `MissingTimestamp` | header absent or unusable |
| `SignatureMismatch` | HMAC did not match any configured secret |
| `TimestampOutOfRange` | outside `tolerance` (default 300s), in either direction |
| `Replayed` | this delivery id has already been seen |
| `BodyTooLarge` | over `max_body_bytes`, checked before anything parses |

## Reacting to a delivery

```php
use Simtabi\Laranail\Python\Events\PythonCallbackReceived;

Event::listen(function (PythonCallbackReceived $event): void {
    $event->envelope->taskId;   // the task it belongs to
    $event->envelope->status;   // succeeded | failed | running | pending
    $event->envelope->payload;  // the decoded body
});
```

Holding a `CallbackEnvelope` **is** the proof that the signature, the timestamp
window and the replay check all passed — only the verifier constructs one. There
is nothing left for a listener to re-check, and nothing it can forget.

## Rotating the secret

`secrets` is a list. Verification tries each in order; signing always uses the
first.

```php
'secrets' => [env('PYTHON_CALLBACK_SECRET_NEW'), env('PYTHON_CALLBACK_SECRET')],
```

Prepend the new one, deploy, move the services over, then drop the tail. At no
point is there a window where valid deliveries are refused.

---
[← Docs index](../../README.md#documentation)
