# Contributing

Thanks for helping. This package runs local processes and can open an
unauthenticated HTTP endpoint, so a few things are stricter here than usual.

## Getting set up

```bash
composer install
composer test
composer lint
```

`composer lint` runs Pint, PHPStan (level max) and Rector. All three must be
clean before a PR.

## The rules that are not negotiable

- **Never build a command string.** Commands are arrays, which bypass the shell.
  An architecture test asserts `shell_exec`, `exec`, `passthru`, `system`,
  `proc_open` and `popen` appear nowhere in the package.
- **`env()` is called in `config/python.php` and nowhere else.** Anywhere else
  it returns null the moment the host runs `config:cache`. Also asserted.
- **A secret the package injects must be registered with the `Redactor`.** If it
  can reach stderr or an exception message, it will.
- **Security tests come before the code they guard.** `ProcessInjectionTest` and
  `CallbackReplayTest` are the shape to follow.

## Tests

Process tests that need a real interpreter are in the `python` group, excluded
by default and run as their own CI job:

```bash
vendor/bin/pest --group=python
```

## Commits

Subject in the imperative, under 72 characters. The body explains why, not what.
No AI attribution.
