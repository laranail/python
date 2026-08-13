# laranail/python

[![Latest version on Packagist](https://img.shields.io/packagist/v/laranail/python.svg)](https://packagist.org/packages/laranail/python)
[![Tests](https://github.com/laranail/python/actions/workflows/tests.yml/badge.svg)](https://github.com/laranail/python/actions/workflows/tests.yml)
[![Static analysis](https://github.com/laranail/python/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/laranail/python/actions/workflows/static-analysis.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

> A Python bridge for Laravel — call FastAPI/Flask services over HTTP, run scripts in a virtualenv behind a hardened process clamp, and receive HMAC-signed callbacks when long work finishes.

Requires PHP `^8.4.1 || ^8.5` on Laravel `^13`.

## Install

```bash
composer require laranail/python
php artisan laranail::python.install
```

## Quick start

```php
use Simtabi\Laranail\Python\Facades\Python;

// A configured HTTP client — Laravel's own, so attach(), sink() and streaming work.
$vector = Python::service('fastapi')->post('/embed', ['text' => $text])->json('vector');

// Or the transport-agnostic call, which returns a result rather than throwing.
$result = Python::run('fastapi:embed', ['text' => $text]);
$result->ok ? $result->get('vector') : report($result->message);

// A local script, from an allow-list. Payload on stdin, JSON back.
$result = Python::run('embed', ['text' => $text]);

// Work too slow for a request: submit, and be called back.
$handle = Python::submit('fastapi:train', ['epochs' => 50], route('python.done'));
```

```bash
php artisan laranail::python.doctor   # every service, its TLS mode, auth, and a live probe
```

## Documentation

Full documentation is at **[opensource.simtabi.com/documentation/laranail/python](https://opensource.simtabi.com/documentation/laranail/python/)** — installation, getting started, configuration, the HTTP service registry, the process bridge and its security model, signed callbacks, async tasks, the commands, testing with `Python::fake()`, and the migration from `laranail/toolkit`.

## Security

This package executes local processes and can expose an unauthenticated HTTP
endpoint, so both are off until you turn them on, and the reasoning behind every
guard is written down in [docs/security.md](docs/security.md). The short version:

- Scripts are named from an allow-list; a caller cannot express a path at all.
- Commands are arrays, never strings, so nothing reaches a shell.
- Payloads travel on stdin, never argv — `/proc/<pid>/cmdline` is world-readable.
- The child gets an allow-listed environment, not yours.
- Callbacks need a valid HMAC over the raw body, a fresh timestamp, **and** an
  unused delivery id. A timestamp window alone does not stop replay.

Report vulnerabilities per [SECURITY.md](SECURITY.md) (opensource@simtabi.com).

## Contributing & security

Issues and PRs are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities per
[SECURITY.md](SECURITY.md) (opensource@simtabi.com); participation follows the [Code of Conduct](CODE_OF_CONDUCT.md).

## License

MIT © Simtabi LLC. See [LICENSE](LICENSE).
