<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Http;

use Simtabi\Laranail\Python\Exceptions\MissingBaseUrlException;
use Simtabi\Laranail\Python\Http\Auth\AuthDefinition;

/**
 * Immutable description of one named service, read from
 * `config('laranail.python.services.<name>')`.
 *
 * Base URL, timeout, retry, TLS, auth and the health contract (path plus the
 * expected key and value) are all data. Adding a service is a config entry, and
 * that property is the whole reason this is a registry rather than a pair of
 * `fastapi()` / `flask()` methods.
 */
final readonly class ServiceDefinition
{
    public function __construct(
        public string $name,
        public string $baseUrl,
        public int $timeout,
        public int $connectTimeout = 5,
        public bool $verifySsl = true,
        public ?string $caCert = null,
        public string $healthPath = '/health',
        public string $healthKey = 'status',
        public string $healthyValue = 'healthy',
        public int $retryTimes = 3,
        public int $retrySleepMs = 100,
        public AuthDefinition $auth = new AuthDefinition,
        /** @var array<string, string> */
        public array $headers = [],
    ) {}

    /**
     * @param array<array-key, mixed> $config
     */
    public static function fromArray(string $name, array $config, int $defaultTimeout, int $defaultConnectTimeout): self
    {
        $auth = $config['auth'] ?? [];

        return new self(
            name: $name,
            baseUrl: self::str($config['base_url'] ?? ''),
            timeout: max(0, self::int($config['timeout'] ?? null, $defaultTimeout)),
            connectTimeout: max(0, self::int($config['connect_timeout'] ?? null, $defaultConnectTimeout)),
            verifySsl: self::bool($config['verify_ssl'] ?? true, true),
            caCert: self::strOrNull($config['ca_cert'] ?? null),
            healthPath: self::str($config['health_path'] ?? null, '/health'),
            healthKey: self::str($config['health_key'] ?? null, 'status'),
            healthyValue: self::str($config['healthy_value'] ?? null, 'healthy'),
            retryTimes: max(0, self::int($config['retry_times'] ?? null, 3)),
            retrySleepMs: max(0, self::int($config['retry_sleep_ms'] ?? null, 100)),
            auth: AuthDefinition::fromArray(is_array($auth) ? $auth : []),
            headers: self::headers($config['headers'] ?? []),
        );
    }

    /**
     * @throws MissingBaseUrlException
     */
    public function requireBaseUrl(): string
    {
        if (trim($this->baseUrl) === '') {
            throw MissingBaseUrlException::for($this->name);
        }

        return $this->baseUrl;
    }

    /**
     * How TLS verification resolves, for the doctor command.
     *
     * `verify_ssl => false` disables verification wholesale and should be
     * visible as such; a CA cert keeps verification on and trusts one extra
     * root, which is nearly always the better answer for a local proxy.
     */
    public function tlsMode(): string
    {
        if (! $this->verifySsl) {
            return 'insecure (verification off)';
        }

        if ($this->caCert === null) {
            return 'verify (system CA bundle)';
        }

        return is_file($this->caCert)
            ? 'verify (custom CA: ' . $this->caCert . ')'
            : 'verify (system CA bundle — configured CA is missing)';
    }

    /**
     * A base URL is never attacker-supplied — it comes from config — but a
     * userinfo component is the classic parser-confusion payload and has no
     * legitimate use here, and a non-HTTP scheme means the value is wrong.
     */
    public function baseUrlProblem(): ?string
    {
        $url = trim($this->baseUrl);

        if ($url === '') {
            return 'no base_url is set';
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'])) {
            return 'base_url is not a valid URL';
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'base_url scheme must be http or https';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'base_url carries credentials in its userinfo component; use the auth block instead';
        }

        return null;
    }

    private static function str(mixed $value, string $default = ''): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    private static function strOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private static function bool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) ? (bool) $value : $default;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $headers = [];

        foreach ($raw as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $headers[$key] = (string) $value;
            }
        }

        return $headers;
    }
}
