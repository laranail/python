<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Enums;

/**
 * How a service authenticates an outbound request.
 *
 * Internal services are almost always authenticated, and the client this
 * package was extracted from had no notion of credentials at all — every
 * consumer bolted its own header on afterwards.
 */
enum AuthScheme: string
{
    case None = 'none';
    case Bearer = 'bearer';
    case ApiKey = 'api_key';
    case Basic = 'basic';

    public static function parse(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::None;
    }

    public function needsToken(): bool
    {
        return $this === self::Bearer || $this === self::ApiKey;
    }
}
