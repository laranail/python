# Changelog

All notable changes to `laranail/python` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
