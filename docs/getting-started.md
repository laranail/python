# Getting started

## Call a service

Point a service at a URL:

```php
// config/laranail/python.php
'services' => [
    'fastapi' => ['base_url' => env('PYTHON_FASTAPI_URL', 'http://127.0.0.1:8000')],
],
```

Then reach it two ways, depending on what you want back.

**The wide seam** hands you Laravel's own client, so everything you know still
works — `attach()`, `sink()`, streaming, `withOptions()`:

```php
use Simtabi\Laranail\Python\Facades\Python;

$response = Python::service('fastapi')->post('/embed', ['text' => $text]);
$vector = $response->json('vector');
```

**The narrow seam** returns a result instead of throwing, which is usually what
you want when the next step is a decision rather than an abort:

```php
$result = Python::run('fastapi:embed', ['text' => $text]);

if ($result->failed()) {
    report($result->message);

    return null;
}

return $result->get('vector');
```

A non-2xx is a result with `ErrorCode::HttpError` and the decoded body, not an
exception. Add `->throw()` when you would rather it were.

## Confirm it works

```bash
php artisan laranail::python.doctor
```

Every service with its resolved base URL, auth scheme, TLS mode and a live
health probe — and a non-zero exit if anything is wrong.

## Run a local script

For code with no service in front of it. Off by default:

```php
'process' => [
    'enabled' => true,
    'scripts' => ['embed' => 'scripts/embed.py'],
    'interpreters' => ['default' => '/srv/app/python/.venv/bin/python'],
],
```

Scripts are **named**, never pathed:

```php
$result = Python::run('embed', ['text' => $text]);
```

The payload arrives on stdin as JSON, and stdout is parsed as JSON:

```python
import json, sys

payload = json.load(sys.stdin)
print(json.dumps({"vector": embed(payload["text"])}))
```

## Hand over slow work

```php
$handle = Python::submit('fastapi:train', ['epochs' => 50], route('python.callback'));

// later, or from the callback
Python::status($handle)->isFinished();
Python::result($handle)?->get('accuracy');
```

Enable `callbacks` and set a secret, and the service reports back when it
finishes rather than being polled. See [tools/callbacks.md](tools/callbacks.md).

## Scaffold a service

```bash
php artisan laranail::python.make-service inference
```

Three files under `python/services/inference`, already speaking the health and
signature contracts this package expects.

---
[← Docs index](../README.md#documentation)
