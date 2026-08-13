## What this changes

<!-- and why. The why is the part that is hard to recover later. -->

## Checklist

- [ ] `composer test` passes
- [ ] `composer lint` passes (Pint, PHPStan level max, Rector)
- [ ] CHANGELOG.md updated under `[Unreleased]`
- [ ] No command is built as a string; commands stay arrays
- [ ] Any secret the package injects is registered with the `Redactor`
- [ ] `env()` is still called only in `config/python.php`
- [ ] Security-relevant behaviour has a test that fails without the fix
