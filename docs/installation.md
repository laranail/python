# Installation

```bash
composer require laranail/python
```

The service provider and the `Python` facade are auto-discovered.

```bash
php artisan laranail::python.install
```

That publishes `config/laranail/python.php` and prints what to do next.

## Requirements

| | |
|---|---|
| PHP | `^8.4.1 \|\| ^8.5` |
| Laravel | `^13.0` |
| Dependencies | `laranail/console`, `laranail/package-tools` |

The PHP floor comes from `laranail/console`, which the laranail command-naming
convention requires for `laranail::` command names.

## Resolving from VCS

laranail packages resolve each other over git rather than Packagist, so a host
application needs the repositories in its own root `composer.json` — Composer
does not read a dependency's own `repositories`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/laranail/python" },
        { "type": "vcs", "url": "https://github.com/laranail/console" },
        { "type": "vcs", "url": "https://github.com/laranail/package-tools" }
    ]
}
```

## What is on by default

Only the HTTP transport. The two features that carry risk stay off until you
ask for them:

| Feature | Default | Why |
|---|---|---|
| HTTP services | on | reaching a configured URL is not a new capability |
| `process.enabled` | **off** | arbitrary code execution reachable from config |
| `callbacks.enabled` | **off** | an unauthenticated POST endpoint |

See [security.md](security.md) for what each one guards once enabled.

---
[← Docs index](../README.md#documentation)
