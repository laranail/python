# Security policy

## Reporting a vulnerability

Email **opensource@simtabi.com**. Please do not open a public issue.

Include the version, a description of the impact, and the smallest reproduction
you can manage. You will get an acknowledgement within three working days.

## What this package's threat model covers

`laranail/python` executes local processes and can expose an unauthenticated
HTTP endpoint, so the following are in scope and treated as vulnerabilities:

- A path that reaches a shell, or any way to execute a script outside the
  configured root.
- Any way to run an interpreter the configuration did not name.
- A secret this package injected appearing in a log, an exception message, or
  a process argument list.
- A forged or replayed callback being accepted.
- The parent environment reaching a child process while `inherit_env` is false.

## Not vulnerabilities

- Enabling `process.enabled` and then registering a script that does something
  dangerous. The allow-list is the boundary; what is on it is your decision.
- Setting `verify_ssl => false`. It is documented as insecure and reported as
  such by `laranail::python.doctor`.
- Turning on `process.allow_arbitrary_paths` and passing a caller-controlled
  path. That opt-in moves the boundary to the root clamp, deliberately.
