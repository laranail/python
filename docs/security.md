# Security

This package does two things most packages do not: it executes local processes,
and it can open an unauthenticated HTTP endpoint. Both are off until you turn
them on, and every guard below exists because the obvious implementation of that
feature is exploitable.

Each row is a threat and the specific mitigation, so you can decide whether the
mitigation is one you trust rather than taking "it's secure" on faith.

## The process transport

| Threat | Mitigation |
|---|---|
| Shell metacharacters in a script name or argument spawn a second command | **The command is an array**, never a string. `illuminate/process` passes an array to `proc_open` without `/bin/sh`, so `;`, a backtick or `$(…)` is an inert byte. There is no code path here that builds a command string, and an architecture test asserts `shell_exec`/`exec`/`passthru`/`system`/`proc_open`/`popen` appear nowhere in the package. |
| A caller reaches `../../.env` or `/tmp/evil.py` | **Scripts are named, not pathed.** `Python::run('embed')` looks `embed` up in `laranail.python.process.scripts`. A caller cannot express a path at all, so there is nothing to sanitise. |
| A *config* entry points outside the root | Even the allow-list resolves through `realpath()` and checks containment. Config is trusted more than a caller, not blindly. |
| An application genuinely needs dynamic paths | Opt in to `allow_arbitrary_paths` and the resolver becomes a root clamp: any path, as long as `realpath()` lands inside the root. `realpath()` collapses `..` **and** follows symlinks, so a link planted inside the root pointing at `/etc` is refused. A `realpath()` of `false` is a refusal, never a fall-through. |
| `--output=/etc/cron.d/x` redirects an argparse script | Any argument starting with `-` is refused unless that script sets `allows_flags`. |
| A secret in a payload leaks to every local user | **The payload goes on stdin**, never argv. `/proc/<pid>/cmdline` is world-readable on Linux, so an API key passed as an argument is readable by any local process for as long as the script runs. The cost is that scripts read stdin, which the scaffold demonstrates. |
| The child inherits `APP_KEY` and database credentials | The environment is an allow-list. `inherit_env` is `false` by default; the child gets `process.env`, the script's own `env`, and a short list it needs to run at all (`PATH`, `LANG`, `TZ`, `HOME`, `TMPDIR`). |
| `$PATH` decides what `python3` means | Interpreters are a config map to **absolute** paths, verified with `is_file()` and `is_executable()`. A bare name is refused unless `allow_path_lookup` is on. The difference between the system interpreter and a project virtualenv is every dependency the script needs. |
| A traceback leaks a secret into a log | stderr is captured and **never logged in full** (`log_stderr` is `false`). What does surface goes through the redactor first — see below. |
| A runaway script exhausts memory | Output is size-checked **before** `json_decode`, and decode depth is clamped. Calling `json_decode` on two gigabytes first is a guaranteed OOM rather than a catchable error. |
| A script hangs | A wall-clock timeout **and** an idle timeout. A script that heartbeats forever but never exits would otherwise run to the wall clock; one that hangs before printing anything holds a worker. |

## Redaction

The redactor masks **literal values the package injected** — the bearer token,
the API key, the HMAC secret, each per-script env value — not just things that
look like secrets.

That ordering matters. Pattern matching on `api_key=…` catches the obvious
shapes and misses the rest, and stderr is exactly where it misses: a Python
traceback embeds local variables, a `requests` exception embeds the full URL
with its query-string token, and a library can print whatever it likes on the
way down. If the package put a value into the call, it can find that value again
wherever it surfaced, whatever framing it picked up. Pattern matching stays as a
second pass for secrets that came from somewhere else.

Values shorter than six characters are ignored — masking a two-character string
would redact half the alphabet out of unrelated text and make the output useless.

## Inbound callbacks

| Threat | Mitigation |
|---|---|
| A forged delivery | HMAC-SHA256 over `"{timestamp}.{rawBody}"`, compared with `hash_equals`. A short-circuiting comparison leaks the correct prefix one byte at a time. |
| A signature over a re-encoded body | The **raw** bytes are signed, never a re-encoded array. Re-encoding makes the signature depend on PHP's JSON encoder agreeing byte-for-byte with Python's, and it invites a "canonicalise, then sign" scheme — which is where essentially every webhook signature CVE lives. |
| A captured request replayed | Two gates, both required. The timestamp tolerance bounds how long a capture stays useful; the **delivery-id claim** stops reuse inside that window. A timestamp window alone does not stop replay, and that is the step most implementations skip. |
| Two concurrent deliveries of the same id | The claim is a single atomic cache `add()`, not `has()` then `put()` — the read-then-write pair has a window where both see "not seen". |
| Rotating the secret causes an outage | `secrets` is a list. Verification tries each; signing uses the first. Prepend the new one, deploy, then drop the tail. |
| An unauthenticated POST endpoint in an app that never uses it | The route is **not registered** unless `callbacks.enabled`. Not registered-and-guarded — absent. |
| An error message tells an attacker which knob to turn | Every failure is a bare `401` with no detail. The reason travels on the `PythonCallbackRejected` event, where your application can alert on it. |
| A cache-filling or DoS attempt | `throttle` from config, and a body-size cap checked before anything parses. |

## Outbound requests

`service()` takes a **name**, not a URL, and there is no `baseUrl()` setter on
the bridge — so no host-supplied value ever becomes a request target. Base URLs
come only from config.

Beyond that, `ServiceDefinition` refuses a non-`http(s)` scheme and a URL
carrying credentials in its userinfo component (`https://user:pass@…`, the
classic parser-confusion payload), and `doctor` warns when a base URL points at
a cloud metadata address.

## What `doctor` will tell you

`php artisan laranail::python.doctor` reports, and exits non-zero on, each of:

- TLS verification switched off for a service.
- An auth scheme configured without its credential, which would send requests
  unauthenticated rather than failing.
- `allow_arbitrary_paths` or `inherit_env` being on.
- Callbacks enabled with no secret, which refuses every delivery.
- A base URL that is malformed, non-HTTP, or carries credentials.

---
[← Docs index](../README.md#documentation)
