# Release process

`laranail/python` is released **tag-driven**: pushing a `vX.Y.Z` tag runs the release workflow, which
publishes the GitHub Release with the CHANGELOG section as its body.

`laranail/*` packages resolve through **git VCS repositories rather than Packagist**, so the tag is the
distribution mechanism. Consumers on `^0.1` pick it up on their next `composer update`.

## Versioning & stability

[Semantic Versioning](https://semver.org). While pre-1.0 the package keeps a single moving `v0.1.0`
tag per the laranail convention.

**What SemVer covers (the public API):**

- The `Python` facade and `Bridge\PythonBridgeManager`'s method surface.
- `Contracts\*` — `PythonTransport`, `PythonHttpClient`, `PythonProcessRunner`, `CallbackVerifier`,
  `ReplayGuard`, `TaskStore`, `ScriptResolver`, `InterpreterResolver`. These are the swap points, so a
  change here breaks anyone who implemented one.
- `ValueObjects\{PythonCall, PythonResult, ProcessCall, ResolvedScript}` and the shipped enums.
- The `Exceptions\*` hierarchy and its codes.
- `Testing\PythonFake` and its assertions.
- `config/python.php` key shapes and the `PYTHON_*` env var names.
- The `laranail::python.{doctor,health,run,install,make-service}` command signatures.
- **The callback wire format** — the signed string, the header names, and the HMAC construction. A
  change here breaks every deployed Python service at once, and they do not upgrade in lockstep with
  the application.

**What is NOT covered:**

- `Support\*` internals and the FastAPI stub's contents.
- Which cache store backs the replay guard or the task store.

### The two things to weigh on every release

**Anything touching the callback contract is a major.** The signature string, header names and
timestamp encoding are a protocol shared with code that lives outside this repository. There is no
deprecation window that helps: a service signing the old way starts failing the moment the application
deploys. If it must change, ship verification for both forms first, release, and remove the old form a
version later.

**Loosening a default in `process` or `callbacks` is a security change, not a convenience one.**
`process.enabled`, `allow_arbitrary_paths`, `allow_path_lookup`, `inherit_env`, `log_stderr` and
`callbacks.enabled` are all deliberately off. Changing a default flips the posture for every consumer
who never read the file.

## Cutting a release

1. Land everything on `main` with `composer lint` (pint + phpstan + rector) and `composer test` green.
   CI runs the 8.4/8.5 matrix, static analysis, the security audit, and the process-transport suite
   against real Python 3.11 and 3.13.
2. Add the `## [X.Y.Z]` block to `CHANGELOG.md` (Keep a Changelog), plus an `UPGRADING.md` section for
   anything breaking.
3. Commit, push, wait for CI green.
4. Tag; the release body is the CHANGELOG block, never a bare stub:

   ```bash
   git tag vX.Y.Z && git push origin vX.Y.Z
   gh release create vX.Y.Z --title "vX.Y.Z" \
     --notes-file <(awk '/^## \[X.Y.Z\]/{f=1;next} /^## \[/{f=0} f' CHANGELOG.md) --generate-notes
   ```

   Pre-1.0, move the existing tag instead:

   ```bash
   git tag -f v0.1.0 && git push origin v0.1.0 --force
   ```

## The scaffold-agreement check

Before tagging anything that touched signing, confirm the shipped FastAPI stub still agrees with
`HmacCallbackVerifier`:

```bash
vendor/bin/pest --filter=ScaffoldSignature
```

The stub exists because the exact HMAC string is otherwise tribal knowledge every consumer gets wrong
once. A stub that has drifted from the verifier is worse than no stub — it is documentation that lies.

## The process-transport check

The security clamp is asserted against a real interpreter, not fixtures PHP happens to run:

```bash
vendor/bin/pest --group=python
```

CI runs this as its own job. Run it locally if you touched anything under `src/Process/`.

---

[← Docs index](../README.md#documentation)
