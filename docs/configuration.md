# Configuration

Everything in `config/python.php`, published by `php artisan laranail::python.install`. Keys live under
`laranail.python.*`.

Three of these blocks are security boundaries rather than preferences — `process`, `callbacks`, and
per-service `verify_ssl`. They are called out where they appear, and covered in full in
[Security](security.md).

## Default service

```php
'default' => env('PYTHON_DEFAULT_SERVICE', 'fastapi'),
```

Which service `Python::service()` uses when no name is given.

## Defaults

Applied to every service that does not override them.

| Key | Default | Notes |
|---|---|---|
| `defaults.timeout` | `30` | Seconds. **This replaced a fallback to `laranail.toolkit.http.request_timeout`** — the package no longer reads toolkit's config. |
| `defaults.connect_timeout` | `5` | Seconds. Separate from `timeout` so a dead host fails fast while a slow response still gets its full budget. |
| `defaults.max_response_bytes` | `8388608` | Checked **before** decode, so an 8 MB reply cannot become a memory exhaustion in `json_decode`. |
| `defaults.json_depth` | `64` | Nesting ceiling. A deeply-nested payload is cheap to send and expensive to parse. |

## Services

Each entry under `services.<name>` describes one Python service. `service()` takes a **name**, never a
URL — there is no `baseUrl()` setter, because a caller-supplied host would make this an SSRF
primitive.

| Key | Default | Notes |
|---|---|---|
| `base_url` | — | Required. |
| `timeout` / `connect_timeout` | `null` | `null` inherits from `defaults`. |
| `verify_ssl` | `true` | `false` is insecure and `laranail::python.doctor` reports it as such. |
| `ca_cert` | `null` | A CA bundle, e.g. mkcert's. |
| `health_path` | `/health` | Probed by `health()` and `healthAll()`. |
| `health_key` | `status` | JSON key checked in the reply. |
| `healthy_value` | `healthy` | Expected value at `health_key`. |
| `retry_times` | `3` | |
| `retry_sleep_ms` | `100` | |
| `auth.scheme` | `none` | `none`, `bearer`, `api_key` or `basic`. |
| `auth.token` / `auth.header` / `auth.username` / `auth.password` | — | Read per scheme. A scheme set without its credential is reported by `doctor`. |
| `headers` | `[]` | Sent with every request to this service. |

`fastapi` and `flask` ship pre-defined with `PYTHON_FASTAPI_*` and `PYTHON_FLASK_*` env names. They
are ordinary entries — add, rename or remove them freely. **They are not methods**; that was the
extraction blocker in the implementation this generalises, where `fastapi()` and `flask()` were
hard-coded.

### Retries do not mask a rejection

Retries fire on connection failures and 5xx only. Laravel's `retry()` throws on **every** non-2xx once
`tries > 1`, so a 422 would otherwise surface as an unreachable service rather than as the validation
error it is.

## Process

Running a local script. **Disabled by default**, and the settings below are the sandbox rather than
tuning knobs.

| Key | Default | Notes |
|---|---|---|
| `process.enabled` | `false` | |
| `process.root` | `null` | Every resolved script is clamped inside this directory. |
| `process.allow_arbitrary_paths` | `false` | `false` means an allow-list of logical names; `true` swaps to a root clamp. Which resolver is installed **is** the security posture. |
| `process.allow_path_lookup` | `false` | `false` means interpreters are absolute paths from `process.interpreters`, so `$PATH` cannot substitute one. |
| `process.timeout` / `idle_timeout` | `60` / `30` | Seconds. Idle timeout catches a process that hangs without exiting. |
| `process.max_output_bytes` | `8388608` | |
| `process.inherit_env` | `false` | The child does **not** get `APP_KEY`, database credentials, or anything else in the parent environment. |
| `process.env` | `[]` | The allow-list of variables it does get. |
| `process.log_stderr` | `false` | stderr is never logged in full by default: tracebacks and `requests` errors embed URLs with API keys. |
| `process.stderr_max_chars` | `2000` | |
| `process.interpreters` | — | Named absolute paths. |
| `process.scripts` | — | Named absolute paths, the allow-list `allow_arbitrary_paths => false` uses. |

Payloads go to the script on **stdin**, never argv — `/proc/<pid>/cmdline` is world-readable.

## Callbacks

Python calling back into Laravel. **Disabled by default, and the route is not registered at all when
disabled** — not registered-and-guarded, so there is no standing unauthenticated endpoint to get
wrong.

| Key | Default | Notes |
|---|---|---|
| `callbacks.enabled` | `false` | |
| `callbacks.prefix` | `api/python` | |
| `callbacks.middleware` | `['api']` | |
| `callbacks.rate_limit` | `'60,1'` | |
| `callbacks.secrets` | `[]` | A **list**. Verified against all, signed with the first, so a rotation does not need an outage. |
| `callbacks.algo` | `sha256` | HMAC over the **raw** body, never a re-encoded array. |
| `callbacks.tolerance` | `300` | Seconds of clock skew allowed. |
| `callbacks.max_body_bytes` | `1048576` | |
| `callbacks.replay_store` | `null` | Cache store for the nonce guard. A timestamp window alone does not stop a replay **inside** the window. |
| `callbacks.signature_header` / `timestamp_header` / `id_header` | `X-Laranail-*` | |

A rejected callback returns a bare `401`; the reason goes to an event, not onto the wire.

## Tasks

Async submit → poll or submit → callback.

| Key | Default |
|---|---|
| `tasks.store` | `null` (the default cache store) |
| `tasks.ttl` | `86400` |
| `tasks.poll_path` | `/tasks/{id}` |
| `tasks.status_key` / `result_key` | `status` / `result` |

## Logging

| Key | Default | Notes |
|---|---|---|
| `logging.enabled` | `false` | |
| `logging.channel` | `null` | The default channel. |

Values the package injected — tokens, passwords, API keys — are masked by `Support\Redactor` before
anything is written. It masks the **literal values it injected**, not just things matching a pattern.

## Migrating from `laranail/toolkit`

`config('laranail.toolkit.python.services.*')` becomes `config('laranail.python.services.*')`. **Env
var names are unchanged**, so an existing `.env` keeps working. One behaviour change: `timeout` used to
fall back to `laranail.toolkit.http.request_timeout` and now has its own `defaults.timeout`.

---

[← Docs index](../README.md#documentation)
