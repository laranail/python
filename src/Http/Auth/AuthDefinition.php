<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Http\Auth;

use Illuminate\Http\Client\PendingRequest;
use Simtabi\Laranail\Python\Enums\AuthScheme;

/**
 * How one service authenticates, read from its config block.
 *
 * The client this package was extracted from had no notion of credentials, so
 * every consumer bolted its own header on at the call site — which meant the
 * token was invisible to the redactor and ended up in tracebacks.
 */
final readonly class AuthDefinition
{
    public function __construct(
        public AuthScheme $scheme = AuthScheme::None,
        public ?string $token = null,
        public string $header = 'X-API-Key',
        public ?string $username = null,
        public ?string $password = null,
    ) {}

    /**
     * @param array<array-key, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $scheme = AuthScheme::parse(is_scalar($config['scheme'] ?? null) ? (string) $config['scheme'] : null);

        return new self(
            scheme: $scheme,
            token: self::nonEmpty($config['token'] ?? null),
            header: self::nonEmpty($config['header'] ?? null) ?? 'X-API-Key',
            username: self::nonEmpty($config['username'] ?? null),
            password: self::nonEmpty($config['password'] ?? null),
        );
    }

    /**
     * Whether the scheme has everything it needs to actually authenticate.
     *
     * A scheme configured without its credential is a misconfiguration worth
     * surfacing in `doctor`, not something to silently send unauthenticated.
     */
    public function isComplete(): bool
    {
        return match ($this->scheme) {
            AuthScheme::None => true,
            AuthScheme::Bearer, AuthScheme::ApiKey => $this->token !== null,
            AuthScheme::Basic => $this->username !== null && $this->password !== null,
        };
    }

    public function apply(PendingRequest $request): PendingRequest
    {
        return match ($this->scheme) {
            AuthScheme::None => $request,
            AuthScheme::Bearer => $this->token === null
                ? $request
                : $request->withToken($this->token),
            AuthScheme::ApiKey => $this->token === null
                ? $request
                : $request->withHeaders([$this->header => $this->token]),
            AuthScheme::Basic => $this->username === null || $this->password === null
                ? $request
                : $request->withBasicAuth($this->username, $this->password),
        };
    }

    /**
     * Every secret this definition would inject, for the redactor.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return array_values(array_filter([$this->token, $this->password]));
    }

    private static function nonEmpty(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
