# Architecture

## Two wide seams, one narrow contract

A "bridge" is only worth extracting if the *call* has the same shape whichever
way it travels. But fully unifying is how bridges become unusable: HTTP's value
is Laravel's fluent `PendingRequest`, and a subprocess's value is stdin/stdout
control. Neither survives a lowest-common-denominator wrapper.

So there is one narrow contract carrying only what both transports can honestly
honour, plus two wide seams that stay directly reachable.

| Seam | Contract | Returns | Reach for it when |
|---|---|---|---|
| Narrow | `Contracts\PythonTransport` | `PythonResult` | you want the call, not the mechanism |
| Wide — HTTP | `Contracts\PythonHttpClient` | `PendingRequest` | you want `attach()`, `sink()`, streaming |
| Wide — process | `Contracts\PythonProcessRunner` | `PythonResult` + output callback | you want incremental stdout or per-call env |

The trade-off: three contracts is more surface than one. The alternative was
wrapping `PendingRequest`, which throws away the best thing the HTTP half
already has.

## Why a result rather than an exception

`Python::run()` returns a `PythonResult` and a non-2xx is a result, not a throw.
Callers of a bridge are usually deciding what to do next — fall back, queue a
retry, degrade — rather than aborting, and a transport that throws on a 422
forces a try/catch around every call for the case a service is *most* likely to
return. `->throw()` is there for callers who would rather not branch.

## Why the process transport is off by default

It is arbitrary code execution reachable from configuration. A package that
arrived with that switched on would be making a decision that is not its to
make. `laranail::python.doctor` reports it as disabled rather than staying
silent, so the "why isn't my script running" question answers itself.

## Why the callback route is absent rather than guarded

A registered-but-guarded endpoint is still an endpoint: it appears in
`route:list`, it is reachable, and it is one misconfiguration away from being
open. Not registering it at all means an application that never uses callbacks
has no callback attack surface, which is a stronger property than a well-tested
guard.

## Why `illuminate/process`, and always an array

An array command goes to `proc_open` without `/bin/sh`, so shell metacharacters
in an argument are inert bytes rather than a second command. Nothing in this
package builds a command string, and an architecture test asserts the shell
functions are never used — including in the `doctor` command, which asks the
interpreter for its version the same way everything else runs a process.

## Why the redactor masks literal values

Pattern matching on `api_key=…` catches the obvious shapes and misses the rest,
and stderr is exactly where it misses. Every secret this package injects is
registered by value, so it can be found again wherever it surfaced and whatever
framing it picked up. See [security.md](security.md).

## What is deliberately not here yet

| Deferred | Why |
|---|---|
| Streaming (SSE / chunked) | needs one abstraction fitting chunked HTTP *and* line-buffered stdout, and nothing is blocked: `service()` returns a `PendingRequest`, and the process side already takes an output callback |
| File and binary transfer | `attach()` / `sink()` already cover it on the HTTP side; the process side should pass shared-disk paths, which needs a filesystem contract this package should not own yet |
| Circuit breaker | a correct one needs shared, atomically-claimed state; a cache approximation across workers hides failures rather than surfacing them, which is worse than not having one. Retries with backoff ship now |
| A shipped queued `Job` | would add `illuminate/bus` and bake in connection opinions; the value objects are serializable and a recipe shows the five-line job |
| A Python-side pip SDK | a second release train and a second language's CI; the scaffold carries the conventions instead |

---
[← Docs index](../README.md#documentation)
