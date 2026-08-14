# Changelog

All notable changes to `laranail/python` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [Unreleased]

### Fixed

- **A process timeout escaped as a vendor exception instead of arriving as a
  `PythonResult`.** A hung script is the reason the clamp exists, so a timeout
  is an expected outcome rather than an exceptional one — but Symfony's
  `ProcessTimedOutException` propagated straight out of `run()`, making the
  timeout the single failure mode a caller could not handle the way it handles
  every other. Both Symfony's and Laravel's classes are caught: they are
  unrelated types (Laravel's extends `RuntimeException`), so catching either
  alone left the other escaping. The rebuilt message also drops the full
  command line the vendor exception embeds, which named the interpreter and
  script path verbatim.

- **The process-transport CI job ran nothing and reported success as failure.**
  It invoked `--group=python`, and no test carried that group — Pest exits 1 on
  "no tests found". The job that exists to assert the security clamp against a
  real interpreter had never asserted anything.

- **`LARANAIL_PYTHON_BIN` was always empty in CI.** The `actions/setup-python`
  step had no `id:`, so `steps.setup-python.outputs.python-path` resolved to an
  empty string and the suite would have fallen back to whatever `python3` was on
  `PATH` — not the matrix version the job named.

- **`composer audit` red-built on a Packagist outage.** The advisory endpoint
  502s often enough to matter; `--ignore-unreachable` keeps a fetch failure from
  being reported as a vulnerability while a real advisory still fails the job.

### Added

- **`Tests\Feature\Process\RealInterpreterTest`** — the process transport
  against a real Python interpreter, in the `python` group. Asserts the payload
  round trip, that a non-zero exit and a timeout both arrive as results, that
  stderr secrets stay out of the message, that the child does not inherit
  `APP_KEY`, and that the payload reaches stdin and never argv.

  `ProcessInjectionTest` proves the guards refuse what they should using PHP as
  the interpreter; this proves what they permit actually works. Neither
  substitutes for the other, and the timeout bug above is what the gap was
  hiding.

## [0.1.0] - 2026-08-13

Initial release. A bidirectional bridge between Laravel and Python.

The HTTP half is extracted from `laranail/toolkit`, where it lived as
`Toolkit\Services\PythonApiService`. See [UPGRADING.md](UPGRADING.md) for the
config-key move; env var names are unchanged, so an existing `.env` keeps
working.

### Added

- **HTTP transport.** Named services resolved from config — base URL, timeout,
  retry, TLS, health contract. Adding a service is a config entry, not code.
  `Python::service('name')` hands back a real `PendingRequest`, so `attach()`,
  `sink()` and streaming all still work.
- **Per-service authentication** — bearer, API-key header, or basic. The client
  this came from had no notion of credentials, so every consumer bolted its own
  header on at the call site, where the redactor could not see it.
- **`healthAll()`** and a `HealthReport` carrying base URL, TLS mode and round
  trip, for readiness endpoints and CI.
- **Process transport.** Runs a registered script in a virtualenv, JSON on
  stdin, JSON on stdout, with a timeout and an idle timeout. Off by default —
  it is arbitrary code execution reachable from configuration.
- **Inbound callbacks.** HMAC-SHA256 over the raw body, a timestamp window,
  **and** a delivery-id replay claim. The route is not registered at all unless
  enabled.
- **Async tasks.** Submit, then poll or be called back. Cache-backed handles.
- **`Python::fake()`** with `assertSent()`, `assertSentTo()`,
  `assertSentTimes()`, `assertNothingSent()`. A fake reports itself as faked
  rather than healthy.
- **Commands** `laranail::python.{doctor,health,run,install,make-service}`.
- **A FastAPI scaffold**, capped at three files, whose signing is asserted
  against the verifier in the test suite.

### Fixed on the way over

- **A client error is no longer retried.** Laravel's `retry()` throws on every
  non-2xx once `tries > 1`, so the `retry(3, 100)` inherited from the seed hit a
  service three times with a request that could never succeed — a 422 is not
  going to become a 200 — and then raised it as an exception instead of
  returning the response. Retries are now limited to connection failures and
  5xx, and a non-2xx comes back as a `PythonResult`.
