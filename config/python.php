<?php

declare(strict_types=1);

/*
 * Merged under the namespaced key "laranail.python" per the laranail
 * convention: read every value as config('laranail.python.*'). When published,
 * this file lands at config/laranail/python.php so Laravel loads it under the
 * same key.
 *
 * env() is called here and nowhere else in the package. Reading env() at any
 * other point returns null the moment the host runs `config:cache`.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default service
    |--------------------------------------------------------------------------
    |
    | Which named service Python::call() reaches when a target names no service.
    |
    */

    'default' => env('PYTHON_DEFAULT_SERVICE', 'fastapi'),

    /*
    |--------------------------------------------------------------------------
    | Shared defaults
    |--------------------------------------------------------------------------
    |
    | Applied to every service that does not override them.
    |
    */

    'defaults' => [
        'timeout' => env('PYTHON_TIMEOUT', 30),
        'connect_timeout' => env('PYTHON_CONNECT_TIMEOUT', 5),
        'max_response_bytes' => 8388608,
        'json_depth' => 64,
    ],

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    |
    | Every service is data. Adding one is an entry here, not code.
    |
    | auth.scheme — none | bearer | api_key | basic. A scheme configured without
    | its credential is reported as incomplete by `laranail::python.doctor`
    | rather than silently sending an unauthenticated request.
    |
    | verify_ssl — set false only against a local self-signed proxy. `ca_cert`
    | is the better answer: it keeps verification on and trusts one extra root.
    |
    */

    'services' => [

        'fastapi' => [
            'base_url' => env('PYTHON_FASTAPI_URL', 'http://127.0.0.1:8000'),
            'timeout' => env('PYTHON_FASTAPI_TIMEOUT'),
            'connect_timeout' => env('PYTHON_FASTAPI_CONNECT_TIMEOUT'),
            'verify_ssl' => env('PYTHON_FASTAPI_VERIFY_SSL', true),
            'ca_cert' => env('PYTHON_FASTAPI_CA_CERT'),
            'health_path' => env('PYTHON_FASTAPI_HEALTH_PATH', '/health'),
            'health_key' => env('PYTHON_FASTAPI_HEALTH_KEY', 'status'),
            'healthy_value' => env('PYTHON_FASTAPI_HEALTHY_VALUE', 'healthy'),
            'retry_times' => env('PYTHON_FASTAPI_RETRY_TIMES', 3),
            'retry_sleep_ms' => env('PYTHON_FASTAPI_RETRY_SLEEP_MS', 100),
            'auth' => [
                'scheme' => env('PYTHON_FASTAPI_AUTH', 'none'),
                'token' => env('PYTHON_FASTAPI_TOKEN'),
                'header' => env('PYTHON_FASTAPI_AUTH_HEADER', 'X-API-Key'),
                'username' => env('PYTHON_FASTAPI_USERNAME'),
                'password' => env('PYTHON_FASTAPI_PASSWORD'),
            ],
            'headers' => [],
        ],

        'flask' => [
            'base_url' => env('PYTHON_FLASK_URL', 'http://127.0.0.1:5000'),
            'timeout' => env('PYTHON_FLASK_TIMEOUT'),
            'connect_timeout' => env('PYTHON_FLASK_CONNECT_TIMEOUT'),
            'verify_ssl' => env('PYTHON_FLASK_VERIFY_SSL', true),
            'ca_cert' => env('PYTHON_FLASK_CA_CERT'),
            'health_path' => env('PYTHON_FLASK_HEALTH_PATH', '/health'),
            'health_key' => env('PYTHON_FLASK_HEALTH_KEY', 'status'),
            'healthy_value' => env('PYTHON_FLASK_HEALTHY_VALUE', 'healthy'),
            'retry_times' => env('PYTHON_FLASK_RETRY_TIMES', 3),
            'retry_sleep_ms' => env('PYTHON_FLASK_RETRY_SLEEP_MS', 100),
            'auth' => [
                'scheme' => env('PYTHON_FLASK_AUTH', 'none'),
                'token' => env('PYTHON_FLASK_TOKEN'),
                'header' => env('PYTHON_FLASK_AUTH_HEADER', 'X-API-Key'),
                'username' => env('PYTHON_FLASK_USERNAME'),
                'password' => env('PYTHON_FLASK_PASSWORD'),
            ],
            'headers' => [],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Local process transport
    |--------------------------------------------------------------------------
    |
    | Off by default. This is arbitrary code execution reachable from
    | configuration, so it takes a deliberate `true` rather than arriving with
    | the package.
    |
    | scripts — a map of LOGICAL NAME => path relative to `root`. Callers name a
    | script; they never pass a path. Adding one is a config entry, the same
    | property the service registry has.
    |
    | allow_arbitrary_paths — swaps the allow-list for a root clamp, where any
    | path is accepted as long as realpath() lands inside `root`. realpath()
    | resolves both ../ and symlinks, so a symlink planted inside the root and
    | pointing at /etc is still refused.
    |
    | inherit_env — false means the child gets only the values below, not the
    | parent's environment. A Python script does not need APP_KEY or
    | DB_PASSWORD, and a traceback prints whatever it can reach.
    |
    | log_stderr — false because a Python traceback embeds local variables and a
    | requests exception embeds the full URL, query-string token included.
    |
    */

    'process' => [
        'enabled' => env('PYTHON_PROCESS_ENABLED', false),
        'root' => env('PYTHON_PROCESS_ROOT'),
        'allow_arbitrary_paths' => false,
        'allow_path_lookup' => false,
        'timeout' => env('PYTHON_PROCESS_TIMEOUT', 60),
        'idle_timeout' => env('PYTHON_PROCESS_IDLE_TIMEOUT', 30),
        'max_output_bytes' => 8388608,
        'log_stderr' => false,
        'stderr_max_chars' => 2000,
        'inherit_env' => false,
        'env' => [],

        'interpreters' => [
            'default' => env('PYTHON_BIN'),
        ],

        'scripts' => [
            // 'embed' => 'scripts/embed.py',
            // 'train' => [
            //     'path'         => 'scripts/train.py',
            //     'interpreter'  => 'ml',
            //     'timeout'      => 900,
            //     'allows_flags' => false,
            //     'env'          => ['MODEL_DIR' => '/var/models'],
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound callbacks
    |--------------------------------------------------------------------------
    |
    | Long-running work finishes asynchronously and has to report back. The
    | route is NOT registered unless this is enabled — an unauthenticated POST
    | endpoint in every application that never uses one is a standing liability.
    |
    | secrets — a list, verified against in order, signed with the first.
    | Rotate by prepending the new one, deploying, then dropping the tail.
    |
    | tolerance — how far a timestamp may be from now, in seconds. This bounds
    | replay but does not stop it, which is what the delivery-id cache is for.
    |
    */

    'callbacks' => [
        'enabled' => env('PYTHON_CALLBACKS_ENABLED', false),
        'prefix' => env('PYTHON_CALLBACK_PREFIX', 'api/python'),
        'middleware' => ['api'],
        'rate_limit' => '60,1',
        'secrets' => array_values(array_filter([env('PYTHON_CALLBACK_SECRET')])),
        'algo' => 'sha256',
        'tolerance' => 300,
        'signature_header' => 'X-Laranail-Signature',
        'timestamp_header' => 'X-Laranail-Timestamp',
        'id_header' => 'X-Laranail-Id',
        'max_body_bytes' => 1048576,
        'replay_store' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Async tasks
    |--------------------------------------------------------------------------
    */

    'tasks' => [
        'store' => null,
        'ttl' => 86400,
        'poll_path' => '/tasks/{id}',
        'status_key' => 'status',
        'result_key' => 'result',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Off by default: a service called once per request turns this into a
    | disk-space incident rather than an audit trail.
    |
    */

    'logging' => [
        'enabled' => env('PYTHON_LOGGING', false),
        'channel' => null,
    ],

];
